# This will overwrite the sandbox with a dump from the live database
# suitable for being run by cron
databasename=$(php db_live.php)
today=$(date +"%Y-%m-%d-%H-%M-%S")
filename="../data/db_dumps/${databasename}_${today}.sql.gz"
mysqldump $databasename | gzip > $filename
mysqladmin -f drop sandbox
mysqladmin create sandbox
gunzip < $filename | mysql sandbox