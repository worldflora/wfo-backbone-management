
# fixme - need to pass in version and passwords

if [[ -z $1 ]];
then 
    echo "You need to pass a version number of the form 2023-12."
    exit 1
else
    version=$1
    echo "Version = $version"
fi

if [[ -z $2 ]];
then 
    echo "You need to pass the solr credentials user:pass"
    exit 1
else
    echo "User:Pass  $2"
fi

rm   ../data/versions/plant_list_$version.json
php -d memory_limit=6G gen_plant_list.php $version
curl -H 'Content-type:application/json' 'http://localhost:8983/solr/wfo-weekly/update?commit=true' -X POST -T ../data/versions/plant_list_$version.json --user $2
gzip -f ../data/versions/plant_list_$version.json
rm ../data/versions/plant_list_$version.json
