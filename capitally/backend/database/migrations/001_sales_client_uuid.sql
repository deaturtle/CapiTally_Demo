-- Only for databases imported BEFORE offline support was added. New installs already have this in schema.sql.
USE capitally;
ALTER TABLE sales
  ADD COLUMN client_uuid CHAR(36) NULL AFTER notes,
  ADD UNIQUE KEY uq_sales_client (store_id, client_uuid);
