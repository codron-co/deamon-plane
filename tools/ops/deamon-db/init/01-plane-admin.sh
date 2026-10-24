#!/bin/bash
# First init only (empty data dir): the admin login Plane uses to create site
# databases and users. It may only connect over the deamon-db network.
set -eu
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS 'plane_admin'@'%' IDENTIFIED BY '${PLANE_ADMIN_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO 'plane_admin'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
