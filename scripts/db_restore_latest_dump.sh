# This takes the latest daily backup  of the db 
# and overwrites the local promethius database with it.
# This script assumes username and password are configured in .my.cnf
filename=$(ls -tp ../data/db_dumps | grep -v /$ | head -1)
filepath="../data/db_dumps/${filename}"
mysql -e "DROP DATABASE IF EXISTS promethius"
mysql -e "CREATE DATABASE promethius"
start=$(date +"%H:%M:%S")
echo "This may take a while. Starting at $start"
gunzip < $filepath | mysql promethius
end=$(date +"%H:%M:%S")
echo "Finished at $end"
echo "All done!"