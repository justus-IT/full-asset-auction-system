# Institution Asset Auction System

PHP 8+ (PDO pgsql, curl, fileinfo) with Supabase PostgreSQL as the only database.

## Setup
1. Create a Supabase project. Open SQL Editor and run `database/schema.sql`.
2. Storage: create a **public** bucket named `asset-images`.
3. Supabase > Connect: copy the **Session pooler** host, port and user. Settings > API: copy the project URL and the `service_role` key (server only).
4. Copy `.env.example` to `.env` and fill in the values. On a live host, set them as environment variables or keep `.env` outside the web root. Never commit it.
5. Optional demo data: run `database/seed.sql` (password `ChangeMe#2026`, accounts `admin@institution.test`, `bidder1@institution.test`, `bidder2@institution.test`). Change these before production.
6. Run locally: `php -S localhost:8000 -t public` (enable `pdo_pgsql`, `curl`, `fileinfo` in php.ini). Under XAMPP, point an Apache virtual host's DocumentRoot at `public/`.
7. Closing auctions: schedule `php cron/close_auctions.php` every minute, or use the "Finalize expired auctions" button on the admin dashboard. Auctions are also finalized when auction pages load.

## Acceptance test
Admin: log in, register an asset with an image, create and publish an auction. Bidder one: register, bid. Bidder two: bid higher; bidder one gets an outbid notification. After the end time, finalize: the winner is notified, the asset becomes sold, and the audit trail shows each step.

## Security notes
Bids are validated and serialized inside PostgreSQL (`place_bid`), using database time. RLS is on for all tables with no public policies; the browser never queries Supabase directly. Authorization is enforced in PHP on every request.

## Not yet included
Reports and CSV export, audit log page, user profile page, admin bid list, and role changes. Auction editing beyond publish/cancel is also pending.
