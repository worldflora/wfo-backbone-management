<?php
/*

    Summary object of a previous placement of a name by data release date

*/

class PreviousPlacement{


    public ?int $nameId;
    public ?String $nameWfo;
    public ?int $placementId;
    public ?String $placementWfo;
    public ?String $role;
    public ?String $classificationId;


    private function __construct($nameId, $nameWfo, $placementId, $placementWfo, $role, $classificationId){

        $this->nameId = $nameId;
        $this->nameWfo = $nameWfo;
        $this->placementId = $placementId;
        $this->placementWfo = $placementWfo;
        $this->role = $role;
        $this->classificationId = $classificationId;

    }

    public static function getPlacements($nameId){
        
        global $mysqli;

        $out = array();

        $sql = "SELECT * FROM previous_classification WHERE name_id = $nameId";
        $response = $mysqli->query($sql);

        while($row = $response->fetch_assoc()){

        }

        return $out;
    }


}