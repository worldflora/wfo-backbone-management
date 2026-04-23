<?php

/**
 * 
 * A class to represent a reference to an external source.
 * 
 */
class Reference{


    // for working the singletons to keep the memory down and allow equality comparisons
    protected static $loaded = array();
    protected static $kindEnumeration = null;
    
    private ?int $id = null;
    private ?string $kind = null;
    private ?string $displayText = null;
    private ?string $linkUri = null;
    private ?string $thumbnailUri = null;
    private ?int $userId = null;
    private bool $thumbnailChecked = false; // flagged as true if we have done a thumbnail lookup to the source
    private ?string $created = null;
    private ?string $modified = null;

    /**
     * Don't call this directly. Use the getRank factory method
     * 
     */
    public function __construct($init_value){

        $this->id = $init_value;
        
        if($this->id){
            $this->load();
            // we now become a singleton so we don't have to be loaded again
            self::$loaded[$init_value] = $this;
        }


    }

    public function load(){

        global $mysqli;

        if(!$this->id) throw new ErrorException("You can't call load on a Reference that doesn't have an db id yet");

        $result = $mysqli->query("SELECT * FROM `references` WHERE `id` = {$this->id} ");
        if($mysqli->error) echo $mysqli->error;
        $row = $result->fetch_assoc();

        $this->kind = $row['kind'];
        $this->displayText = $row['display_text'];
        $this->linkUri = $row['link_uri'];
        $this->thumbnailUri = $row['thumbnail_uri'];
        $this->userId = $row['user_id'];
        $this->created = $row['created'];
        $this->modified = $row['modified'];

        //$result->close();

    }


    public static function getReference($id){
        if(isset(self::$loaded[$id])) return self::$loaded[$id];
        return new Reference($id);
    }

    public function save(){

        global $mysqli;

        //error_log("we are saving a reference");

        if(!$this->linkUri) throw new ErrorException("You can't save a reference with no link uri.");
        if(strlen($this->linkUri) < 1) throw new ErrorException("You must set a display string for a reference.");

        $updateResponse = new UpdateResponse('ReferenceSave', true, "Saving reference");

        // we absolutely must not create duplicate link_uri - in fact there is a unique index on that col to stop it
        // so we will overwrite an existing record if it exists.
        //  - we don't want the uri as the primary key because we make joins to this table and they become really inefficient.
        $dupe = Reference::getReferenceByUri($this->linkUri);
        if($dupe){
            // we will now be saved over the top of the existing record even if we are a new record
            // of course this could be ourselves in which case no harm as the id will be the same!
            $this->id = $dupe->getId();
        }

        if($this->thumbnailChecked){
            $thumbnail_last = 'now()';
        }else{
            $thumbnail_last = 'NULL';
        }
        
        
        if($this->id){

            //error_log("\t updating");
            //error_log($this->userId);

            // we have an id so exist in the db and are being updated
            // we have a real db id so we can do an update
            $stmt = $mysqli->prepare("UPDATE `references`
            SET 
                `link_uri` = ? ,
                `thumbnail_uri` = ? ,
                `display_text` = ? ,
                `kind` = ? ,
                `user_id` = ?,
                `thumbnail_last_check` = $thumbnail_last
            WHERE `id` = ?");
            if($mysqli->error) echo $mysqli->error; // should only have prepare errors during dev
            $stmt->bind_param("ssssii", 
                $this->linkUri,
                $this->thumbnailUri,
                $this->displayText,
                $this->kind,
                $this->userId,
                $this->id
            );
            if(!$stmt->execute()){
                //echo $mysqli->error;
                $updateResponse->message = $mysqli->error;
                $updateResponse->success = false;
                return $updateResponse; // let them know we failed
            }
            
            $updateResponse->message = "Reference saved.";
            $updateResponse->success = true;
            return $updateResponse;

        }else{
            
            // we have no id so this is an insert call
            $stmt = $mysqli->prepare("INSERT 
                INTO `references`(`link_uri`, `thumbnail_uri`, `display_text`, `kind`, `user_id`, `thumbnail_last_check`) 
                VALUES (?,?,?,?,?, $thumbnail_last)");
            if($mysqli->error) echo $mysqli->error; // should only have prepare errors during dev
            $stmt->bind_param("ssssi", 
                $this->linkUri,
                $this->thumbnailUri,
                $this->displayText,
                $this->kind,
                $this->userId
            );
            if(!$stmt->execute()){
                $updateResponse->message = $mysqli->error;
                $updateResponse->success = false;
                return $updateResponse; // let them know we failed
            }

            // get our real id
            $this->id = $mysqli->insert_id;

            // we now become a singleton so we don't have to be loaded again
            self::$loaded[$this->id] = $this;

            $updateResponse->message = "Reference created.";
            $updateResponse->success = true;
            return $updateResponse;

        }

    }

    /**
     * Get the value of modified
     */ 
    public function getModified()
    {
        return $this->modified;
    }

    /**
     * Get the value of created
     */ 
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Get the value of userId
     */ 
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Set the value of userId
     */ 
    public function setUserId($user_id){
        $this->userId = $user_id;
    }

    /**
     * Get the value of thumbnailUri
     */ 
    public function getThumbnailUri(){
        if(!$this->thumbnailUri) return null;
        if(preg_match('/commons\.wikimedia\.org/', $this->thumbnailUri)) return $this->getThumbnailUriWikimedia();
         return $this->thumbnailUri;
    }

    private function getThumbnailUriWikimedia(){

        /*
        we do some work if we know the "thumbnail" URI is likely to be to a larger file

        Explanation from this page

        https://stackoverflow.com/questions/33689980/get-thumbnail-image-from-wikimedia-commons
        
        --------------
        
        If you're okay to rely on the fact the current way of building the URL won't change in the future (which is not guaranteed), then you can do it.
        
        The URL looks like this:
        
        https://upload.wikimedia.org/wikipedia/commons/thumb/a/a8/Tour_Eiffel_Wikimedia_Commons.jpg/200px-Tour_Eiffel_Wikimedia_Commons.jpg
        
        The first part is always the same: https://upload.wikimedia.org/wikipedia/commons/thumb
        The second part is the first character of the MD5 hash of the file name. In this case, the MD5 hash of Tour_Eiffel_Wikimedia_Commons.jpg is a85d416ee427dfaee44b9248229a9cdd, so we get /a.
        The third part is the first two characters of the MD5 hash from above: /a8.
        The fourth part is the file name: /Tour_Eiffel_Wikimedia_Commons.jpg
        The last part is the desired thumbnail width, and the file name again: /200px-Tour_Eiffel_Wikimedia_Commons.jpg
        
        --------------

        e.g. from our things
        http://commons.wikimedia.org/wiki/Special:FilePath/ETH-BIB-Rikli%2C%20Martin%20%281868-1951%29-Portrait-Portr%2015032.tif

        */

        // get the filename from the url
        $file_name_encoded = basename($this->thumbnailUri);
        $file_name = urldecode($file_name_encoded);
        $file_name = str_replace(' ', '_', $file_name);
        $md5 = md5($file_name);
        if(preg_match('/\.tif$/', $file_name) || preg_match('/\.tiff$/', $file_name)){
            $thumbnail_name = "lossy-page1-330px-" .  $file_name . ".jpg";
        }else{
            $thumbnail_name = "330px-" . $file_name;
        }
        $file_name = urlencode($file_name);
        $thumb_uri = "https://upload.wikimedia.org/wikipedia/commons/thumb/" . substr($md5,0,1) . "/" . substr($md5, 0, 2) . "/" . $file_name . '/' . $thumbnail_name;

        return $thumb_uri;

    }

    /**
     * Set the value of thumbnailUri
     *
     */ 
    public function setThumbnailUri($thumbnailUri){
        if(filter_var($thumbnailUri, FILTER_VALIDATE_URL)){
            $this->thumbnailUri = $thumbnailUri;
        }
    }

    /**
     * Get the value of linkUri
     */ 
    public function getLinkUri()
    {
        return $this->linkUri;
    }

    /**
     * Set the value of linkUri
     *
     * @return  bool true if it is a valid uri and therefore set
     */ 
    public function setLinkUri($linkUri)
    {
        $linkUri = trim($linkUri);
       
        // there is an issue with non-ascii characters in the urls 
        // these won't pass the PHP validator 
        // we therefore do a crude parsing and check the schema
        $parts = parse_url($linkUri);

        if($parts && ($parts['scheme'] == 'http' || $parts['scheme'] == 'https') ){
            $this->linkUri = $linkUri;
            return true;
        }else{
            error_log("Invalid URI format: " . $linkUri);
            return false;
        }

        /*

        if (filter_var($linkUri, FILTER_VALIDATE_URL)) {

        } else {
            echo "\nInvalid URI format";
            echo "\n$linkUri";
            return false;
        }

        */



    }

    /**
     * Get the value of displayText
     */ 
    public function getDisplayText()
    {
        return $this->displayText;
    }

    /**
     * Set the value of displayText
     *
     * @return  self
     */ 
    public function setDisplayText($displayText)
    {
        $this->displayText = $displayText;
    }

    /**
     * Get the value of kind
     */ 
    public function getKind()
    {
        return $this->kind;
    }

    /**
     * Set the value of kind
     *
     * @return  self
     */ 
    public function setKind($kind)
    {

        $kinds = Reference::getReferenceKindEnumeration();
        if(in_array($kind, $kinds)){
            $this->kind = $kind;
            return true;
        }else{
            throw new ErrorException("Trying to set illegal Reference kind value: $kind.");
            return false;
        }
        
    }


    public static function getReferenceKindEnumeration(){

        global $mysqli;

        if(!self::$kindEnumeration){
            $result = $mysqli->query("SHOW COLUMNS FROM `references` LIKE 'kind'");
            $row = $result->fetch_assoc();
            $type = $row['Type'];
            preg_match("/'(.*)'/i", $type, $matches);
            $vals = explode(',', $matches[1]);
            array_walk($vals, function(&$v){$v = str_replace("'", "", $v);});
            self::$kindEnumeration = $vals;
            // $result->close();
        }
        return self::$kindEnumeration;

    }

    /**
     * Get the value of id
     */ 
    public function getId()
    {
        return $this->id;
    }

    public static function getReferenceByUri($uri){

        global $mysqli;

        $safe_uri = $mysqli->real_escape_string($uri);

        // we always look for both versions of the URI
        if(preg_match('/^http:\/\//', $safe_uri)){
            $https_version = str_replace('http://', 'https://', $safe_uri);
            $http_version = $safe_uri;
        }else{
            $https_version = $safe_uri;
            $http_version = str_replace('https://', 'http://', $safe_uri);
        }

        $result = $mysqli->query("SELECT id FROM `references` WHERE link_uri = '$https_version' OR link_uri = '$http_version'");
        if($result->num_rows > 0){
            
            $row = $result->fetch_assoc();
            $ref = Reference::getReference($row['id']);

            // if we were asked for the https version but the one received was the http version
            // then we set the link to the https version so that any subsequent save (e.g. updating the display string) will update it.
            if($safe_uri == $https_version && $ref->getLinkUri() == $http_version){
                $ref->setLinkUri($https_version);
            }

            return $ref;

        }else{
            return null;
        }

    }

    /**
     * 
     * This will call the link_uri and attempt to 
     * fetch a thumbnail_uri from it.
     * It only works for certain well known link_uri formats.
     * 
     */
    public function updateThumbnailUri(){
        if(preg_match('/wikidata.org/', $this->getLinkUri())) return $this->updateThumbnailUriWikiData();
        if(preg_match('/gbif.org/', $this->getLinkUri())) return $this->updateThumbnailUriGbif();
        throw new ErrorException("Unsupported link uri to extract a thumbnail from.");
    }

    private function updateThumbnailUriWikiData(){

        global $mysqli;

        echo "{$this->getId()}\tUpdating WikiData link\t";

        if($this->getKind() == 'person'){
        
            // this is complex and maybe should be in the AuthorTeam object?
            // keeping the author_lookup table in sync with the reference links
            // suggests a bad

            echo "is person\t";

            // get the q number - we'll need it
            $matches = array();
            if(preg_match('/www.wikidata.org\/entity\/(Q[0-9]+)$/', $this->getLinkUri(), $matches));
            $q_number = $matches[1];

            // fail if we can't extract a qnumber
            if(!$q_number){
                echo "can't extract Q from {$this->getLinkUri()} \n";
                return;
            } 
            
            // get the data for the entity from wikidata
            // FIXME - catch no response.
            $wiki_data = json_decode(@file_get_contents($this->getLinkUri()));
            if(!$wiki_data){
                echo "FAILED to get uri {$this->getLinkUri()}\n";
                return;
            }

            // if we've been redirected then update the q number.
            if(!isset($wiki_data->entities->{$q_number})){
                echo "old: {$q_number} \t";
                $q_number = array_key_first(get_object_vars($wiki_data->entities));
                echo "new: {$q_number} \t";

                // change the uri of the reference.
                // there is a chance that this will prevent saving because it 
                // already exists - but very unlikely.
                $this->setLinkUri('http://www.wikidata.org/entity/' . $q_number);

            }

            // or an author abbreviation
            if(!isset($wiki_data->entities->{$q_number}->claims->P428)){
                echo "no author abbreviation in the claims\n";
                return;
            } 

            $prop = $wiki_data->entities->{$q_number}->claims->P428[0];
            $author_abbreviation = $prop->mainsnak->datavalue->value;

            echo "$author_abbreviation\t";
           
            // update that in author teams
            
            // are they in the authors table? 
            $safe_uri = $mysqli->real_escape_string($this->getLinkUri());
            $response = $mysqli->query("SELECT * FROM author_lookup WHERE uri = '{$safe_uri}';");
            $rows = $response->fetch_all(MYSQLI_ASSOC);
            if(count($rows)){
                // delete them so we can recreate
                foreach($rows as $row){
                    $mysqli->query("DELETE FROM author_lookup WHERE id = {$row['id']};");
                }
            }
            
            // create a team flagging to call wikidata for data
            $team = new AuthorTeam($author_abbreviation, true);

            // author is now populated in the team
            $member = $team->getMembers()[0];

            if($member->imageUri){
                $this->setThumbnailUri($member->imageUri); // will check it is a pucker uri
                echo $member->imageUri;
            }else{
                echo "no media\t";
            }

        }else{
            echo "not a person - we don't do that here\t";
        }

        echo "\n";
        $this->thumbnailChecked = true;
    }

    private function updateThumbnailUriGbif(){
        echo "{$this->getId()}\tUpdating gbif link\t";

        // lets parse out the occurence id
        $matches = array();
        if(preg_match('/www.gbif.org\/occurrence\/([0-9]+)/', $this->getLinkUri(), $matches)){
            $occurrence_id = $matches[1];

            $api_uri = "https://api.gbif.org/v1/occurrence/$occurrence_id";
            $gbif_data = json_decode(file_get_contents($api_uri));

            if(isset($gbif_data->media) && count($gbif_data->media) > 0){
                foreach($gbif_data->media as $medium){
                    if(isset($medium->format) && $medium->format == 'image/jpeg' && isset($medium->identifier)){
                        $this->setThumbnailUri($medium->identifier); // will check it is a pucker uri
                        echo $medium->identifier;
                        break;
                    }
                }
            }else{
                echo "no media\t";
            }
        }

        echo "\n";
        $this->thumbnailChecked = true;
    }

    public static function resetSingletons(){
        self::$loaded = array();
    }

}