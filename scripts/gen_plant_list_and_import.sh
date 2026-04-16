
# fixme - need to pass in version and passwords

if [[ -z $1 ]];
then 
    echo "You need to pass a version number of the form 2023-12"
    exit 1
else
    echo "Version = $1"
    
fi

if [[ -z $2 ]];
then 
    echo "You need to pass the solr credentials user:pass"
    exit 1
else
    echo "User:Pass  $2"
fi

rm   ../data/versions/plant_list_$1.json
php -d memory_limit=3G gen_plant_list.php $1
curl -H 'Content-type:application/json' 'http://localhost:8983/solr/wfo-api/update?commit=true' -X POST -T ../data/versions/plant_list_$1.json --user $2
gzip -f ../data/versions/plant_list_$1.json
rm ../data/versions/plant_list_$1.json
