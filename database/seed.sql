-- DEMO DATA ONLY. Demo password for every account: ChangeMe#2026
-- Change or delete these accounts before production.
insert into users (full_name, email, password_hash, role, phone) values
 ('System Administrator','admin@institution.test','$2b$10$OsMLmSjwg.V0RltEq0vqmewnLvJyLSdH4.v0oBlwH1zz19Veaf7.G','admin','+254700000001'),
 ('Demo Bidder One','bidder1@institution.test','$2b$10$OsMLmSjwg.V0RltEq0vqmewnLvJyLSdH4.v0oBlwH1zz19Veaf7.G','bidder','+254700000002'),
 ('Demo Bidder Two','bidder2@institution.test','$2b$10$OsMLmSjwg.V0RltEq0vqmewnLvJyLSdH4.v0oBlwH1zz19Veaf7.G','bidder','+254700000003');

insert into assets (asset_code, name, description, category, condition, location, starting_price, created_by) values
 ('A101','Office Chairs (set of 10)','Swivel chairs, worn upholstery.','Furniture','fair','Main Store',15000,1),
 ('A102','Desktop Computers (5 units)','Decommissioned desktops, no storage drives.','ICT Equipment','obsolete','ICT Store',25000,1),
 ('A103','Laser Printer','Network laser printer, needs toner and service.','ICT Equipment','poor','Admin Block',6000,1),
 ('A104','Office Desks (8 units)','Wooden desks with minor damage.','Furniture','fair','Main Store',12000,1),
 ('A105','Projector','Ceiling projector, lamp failed.','ICT Equipment','damaged','Lecture Hall 2',8000,1);

-- One live auction (ends in 3 days) and one draft
insert into auctions (asset_id, start_date, end_date, starting_bid, status, is_published, created_by)
 values (1, now() - interval '1 hour', now() + interval '3 days', 15000, 'active', true, 1);
insert into auctions (asset_id, start_date, end_date, starting_bid, status, is_published, created_by)
 values (2, now() + interval '1 day', now() + interval '5 days', 25000, 'draft', false, 1);
update assets set status='auctioned' where id=1;
