-- Institution Asset Auction System: Supabase PostgreSQL schema
-- Run in the Supabase SQL Editor. Safe to run once on an empty project.

create table users (
  id bigint generated always as identity primary key,
  full_name text not null check (length(full_name) between 2 and 120),
  email text not null,
  password_hash text not null,
  role text not null default 'bidder' check (role in ('admin','bidder')),
  phone text,
  status text not null default 'active' check (status in ('active','inactive')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  last_login timestamptz
);
create unique index users_email_uq on users (lower(email));

create table login_attempts (
  id bigint generated always as identity primary key,
  email text not null,
  ip_address text,
  success boolean not null default false,
  attempted_at timestamptz not null default now()
);
create index login_attempts_lookup on login_attempts (lower(email), attempted_at desc);

create table assets (
  id bigint generated always as identity primary key,
  asset_code text not null unique,
  name text not null,
  description text not null default '',
  category text not null,
  condition text not null check (condition in ('good','fair','poor','damaged','obsolete')),
  location text not null,
  image_url text,
  starting_price numeric(14,2) not null check (starting_price > 0),
  status text not null default 'available' check (status in ('available','auctioned','sold','withdrawn')),
  created_by bigint not null references users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index assets_status_idx on assets (status);
create index assets_category_idx on assets (category);

create table auctions (
  id bigint generated always as identity primary key,
  asset_id bigint not null references assets(id),
  start_date timestamptz not null,
  end_date timestamptz not null,
  starting_bid numeric(14,2) not null check (starting_bid > 0),
  current_bid numeric(14,2),
  current_bidder_id bigint references users(id),
  status text not null default 'draft' check (status in ('draft','scheduled','active','closed','cancelled')),
  is_published boolean not null default false,
  winner_id bigint references users(id),
  winning_bid numeric(14,2),
  created_by bigint not null references users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  check (end_date > start_date)
);
create index auctions_status_end_idx on auctions (status, end_date);
create index auctions_asset_idx on auctions (asset_id);
-- One live auction per asset
create unique index auctions_one_live_per_asset
  on auctions (asset_id) where status in ('scheduled','active');

create table bids (
  id bigint generated always as identity primary key,
  auction_id bigint not null references auctions(id),
  bidder_id bigint not null references users(id),
  amount numeric(14,2) not null check (amount > 0),
  bid_time timestamptz not null default now(),
  status text not null default 'valid' check (status in ('valid','winning','outbid','rejected')),
  created_at timestamptz not null default now()
);
create index bids_auction_amount_idx on bids (auction_id, amount desc);
create index bids_bidder_idx on bids (bidder_id, bid_time desc);

create table notifications (
  id bigint generated always as identity primary key,
  user_id bigint not null references users(id) on delete cascade,
  title text not null,
  message text not null,
  type text not null check (type in ('new_bid','outbid','auction_won','auction_closed','system')),
  is_read boolean not null default false,
  created_at timestamptz not null default now()
);
create index notifications_user_idx on notifications (user_id, is_read, created_at desc);

create table audit_logs (
  id bigint generated always as identity primary key,
  user_id bigint references users(id),
  action text not null,
  entity_type text not null,
  entity_id bigint,
  description text,
  ip_address text,
  created_at timestamptz not null default now()
);
create index audit_logs_created_idx on audit_logs (created_at desc);

-- Audit logs are append-only, even for the application role.
create or replace function audit_logs_immutable() returns trigger language plpgsql as $$
begin raise exception 'audit_logs is append-only'; end $$;
create trigger audit_logs_no_update before update or delete on audit_logs
  for each row execute function audit_logs_immutable();

-- Row Level Security: the browser never talks to these tables directly.
-- With RLS on and no policies, the anon and authenticated API roles get no access.
-- The PHP server connects with the database role (bypasses RLS) and enforces
-- authorization in code.
alter table users enable row level security;
alter table login_attempts enable row level security;
alter table assets enable row level security;
alter table auctions enable row level security;
alter table bids enable row level security;
alter table notifications enable row level security;
alter table audit_logs enable row level security;

-- Atomic bid placement. The auction row is locked, so concurrent bids serialize.
-- Time checks use the database clock (now()).
create or replace function place_bid(p_auction_id bigint, p_bidder_id bigint, p_amount numeric, p_ip text default null)
returns jsonb language plpgsql as $$
declare
  a auctions%rowtype;
  u users%rowtype;
  asset_row assets%rowtype;
  prev_bidder bigint;
  min_bid numeric;
begin
  select * into u from users where id = p_bidder_id;
  if not found or u.status <> 'active' or u.role <> 'bidder' then
    return jsonb_build_object('ok', false, 'error', 'Your account is inactive.');
  end if;

  select * into a from auctions where id = p_auction_id for update;
  if not found then
    return jsonb_build_object('ok', false, 'error', 'Auction not found.');
  end if;
  if a.status in ('draft','cancelled') or not a.is_published then
    return jsonb_build_object('ok', false, 'error', 'This auction is not open for bidding.');
  end if;
  if a.status = 'closed' or now() >= a.end_date then
    return jsonb_build_object('ok', false, 'error', 'This auction has already closed.');
  end if;
  if now() < a.start_date then
    return jsonb_build_object('ok', false, 'error', 'This auction has not started yet.');
  end if;

  if a.current_bid is null then
    min_bid := a.starting_bid;
    if p_amount < min_bid then
      return jsonb_build_object('ok', false, 'error', 'Your bid must be at least the starting bid.', 'min_bid', min_bid);
    end if;
  else
    min_bid := a.current_bid + 0.01;
    if p_amount <= a.current_bid then
      return jsonb_build_object('ok', false, 'error', 'Your bid must be higher than the current highest bid.', 'min_bid', min_bid);
    end if;
  end if;

  select * into asset_row from assets where id = a.asset_id;
  prev_bidder := a.current_bidder_id;

  update bids set status = 'outbid' where auction_id = a.id and status = 'winning';
  insert into bids (auction_id, bidder_id, amount, status) values (a.id, p_bidder_id, p_amount, 'winning');

  update auctions
     set current_bid = p_amount, current_bidder_id = p_bidder_id,
         status = 'active', updated_at = now()
   where id = a.id;

  if prev_bidder is not null and prev_bidder <> p_bidder_id then
    insert into notifications (user_id, title, message, type)
    values (prev_bidder, 'You have been outbid',
            'You have been outbid on ' || asset_row.name || ' #' || asset_row.asset_code || '.', 'outbid');
  end if;

  insert into audit_logs (user_id, action, entity_type, entity_id, description, ip_address)
  values (p_bidder_id, 'Submitted Bid', 'auction', a.id, 'Amount: KES ' || to_char(p_amount, 'FM999,999,999,990.00'), p_ip);

  return jsonb_build_object('ok', true, 'message', 'Bid submitted successfully.', 'current_bid', p_amount);
end $$;

-- Finalizes every auction whose end time has passed. Idempotent; call from an
-- admin action, a cron job, or lazily on page loads. Returns the number closed.
create or replace function close_expired_auctions(p_actor bigint default null)
returns integer language plpgsql as $$
declare
  a auctions%rowtype;
  top bids%rowtype;
  asset_row assets%rowtype;
  closed_count integer := 0;
begin
  -- Promote scheduled auctions whose start time has arrived
  update auctions set status = 'active', updated_at = now()
   where status = 'scheduled' and is_published and start_date <= now() and end_date > now();

  for a in
    select * from auctions
     where status in ('scheduled','active') and is_published and end_date <= now()
     order by id for update skip locked
  loop
    select * into asset_row from assets where id = a.asset_id;
    select * into top from bids where auction_id = a.id
     order by amount desc, bid_time asc, id asc limit 1;

    if found then
      update bids set status = 'outbid' where auction_id = a.id and id <> top.id and status in ('valid','winning');
      update bids set status = 'winning' where id = top.id;
      update auctions set status = 'closed', winner_id = top.bidder_id, winning_bid = top.amount,
             current_bid = top.amount, current_bidder_id = top.bidder_id, updated_at = now()
       where id = a.id;
      update assets set status = 'sold', updated_at = now() where id = a.asset_id;

      insert into notifications (user_id, title, message, type)
      values (top.bidder_id, 'Auction won',
              'Congratulations. You won the auction for ' || asset_row.name || ' #' || asset_row.asset_code || '.', 'auction_won');
      insert into notifications (user_id, title, message, type)
      select distinct b.bidder_id, 'Auction closed',
             'The auction for ' || asset_row.name || ' #' || asset_row.asset_code || ' has closed.', 'auction_closed'
        from bids b where b.auction_id = a.id and b.bidder_id <> top.bidder_id;

      insert into audit_logs (user_id, action, entity_type, entity_id, description)
      values (p_actor, 'Auction Closed', 'auction', a.id,
              'Winner user ' || top.bidder_id || ', winning bid KES ' || to_char(top.amount, 'FM999,999,999,990.00'));
    else
      update auctions set status = 'closed', updated_at = now() where id = a.id;
      update assets set status = 'available', updated_at = now() where id = a.asset_id;
      insert into audit_logs (user_id, action, entity_type, entity_id, description)
      values (p_actor, 'Auction Closed', 'auction', a.id, 'Closed with no bids');
    end if;
    closed_count := closed_count + 1;
  end loop;
  return closed_count;
end $$;

-- Storage: create a PUBLIC bucket named "asset-images" in Storage (Dashboard > Storage).
-- Uploads are done server-side with the service-role key; no storage policies for anon are needed.
