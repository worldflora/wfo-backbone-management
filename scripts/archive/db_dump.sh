
# This script assumes username and password are configured in .my.cnf

echo "\n*** Dumping DB ***\n"

if [[ ! $1 =~ ^[A-B]{1}$ ]]
then
    echo "\nYou must specify which copy of the db to download, A or B.\n"
    exit 1
fi
databasename="promethius_$1";

today=$(date +"%Y-%m-%d-%H-%M-%S")
filename="../data/db_dumps/${databasename}_${today}.sql.gz"
mysqldump $databasename | gzip > $filename
echo "Database dumped to: ${filename}"
