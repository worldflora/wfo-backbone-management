
# This script assumes username and password are configured in .my.cnf

# takes the first argument as the file name within the data/db_dumps directory

echo "\n*** Importing DB ***"

# we must have a version 
if [[ ! $1 =~ ^[A-B]{1}$ ]]
then
    echo "\nYou must specify which copy to overwrite A or B followed by the filename.\n"
    exit 1
fi
databasename="promethius_$1";

# we must have a file 
filename="../data/db_dumps/$2"
if [ ! -f "$filename" ]
then
    echo "\nYou must specify an existing datadabase dump.\n"
    echo "$filename does not exist\n"
    exit 1
fi

# They really must confirm what they are doing .
echo "\nYou are about to overwrite database -> $databasename\n";
echo "with the data in the file -> $filename \n";
echo "Are you sure?\n"

read -p "Please confirm you have backed up $databasename (type the word 'yes'):"  -r
if [[ $REPLY != "yes" ]]
then
    echo "Go run 'db_dump.sh $1' and then come back\n"
    exit 1
fi

echo "\nDropping $databasename";
mysqladmin drop $databasename

echo "\nCreating new $databasename"
mysqladmin create $databasename

echo "Importing $filename"
start=$(date +"%H:%M:%S")
echo "This may take a while. Starting at $start"
gunzip < $filename | mysql $databasename
end=$(date +"%H:%M:%S")
stop=$(date +"%H:%M:%S")
echo "Finished at $stop"
echo "All done!"
