<?php

use GraphQL\Type\Definition\EnumType;

require_once('../include/TaxonGqlType.php');
require_once('../include/NameGqlType.php');
require_once('../include/NameMatchesGqlType.php');
require_once('../include/NameIpniDifferencesGqlType.php');
require_once('../include/RankGqlType.php');
require_once('../include/UpdateResponseGqlType.php');
require_once('../include/NamePlacerGqlType.php');
require_once('../include/NamePlacer.php');
require_once('../include/SynonymMoverGqlType.php');
require_once('../include/ChildMoverGqlType.php');
require_once('../include/UnplacedFinderGqlType.php');
require_once('../include/BasionymFinderGqlType.php');
require_once('../include/IdentifierGqlType.php');
require_once('../include/UserGqlType.php');
require_once('../include/DownloadFileGqlType.php');
require_once('../include/StatsBasicSummaryGqlType.php');
require_once('../include/ReferenceGqlType.php');
require_once('../include/ReferenceUsageGqlType.php');
require_once('../include/AuthorTeamMemberGqlType.php');

/*

    Register of types because the schema must only have one instance 
    of each type in it.

*/

class TypeRegister {

    private static $taxonType;
    private static $nameType;
    private static $nameMatchesType;
    private static $nameIpniDifferencesType;
    private static $rankType;
    private static $updateResponseType;
    private static $namePlacerType;
    private static $synonymMoverType;
    private static $childMoverType;
    private static $placementActionEnum;
    private static $unplacedFinderType;
    private static $basionymFinderType;
    private static $identifierType;
    private static $userType;
    private static $downloadFileType;
    private static $statsBasicSummaryType;
    private static $referenceType;
    private static $referenceUsageType;
    private static $authorTeamMemberType;

    public static function taxonType(){
        return self::$taxonType ?: (self::$taxonType = new TaxonGqlType());
    }

    public static function nameType(){
        return self::$nameType ?: (self::$nameType = new NameGqlType());
    }
    
    public static function nameMatchesType(){
        return self::$nameMatchesType ?: (self::$nameMatchesType = new NameMatchesGqlType());
    }

    public static function nameIpniDifferencesType(){
        return self::$nameIpniDifferencesType ?: (self::$nameIpniDifferencesType = new NameIpniDifferencesGqlType());
    }

    public static function rankType(){
        return self::$rankType ?: (self::$rankType = new RankGqlType());
    }

    public static function updateResponseType(){
        return self::$updateResponseType ?: (self::$updateResponseType = new UpdateResponseGqlType());
    }

    public static function namePlacerType(){
        return self::$namePlacerType ?: (self::$namePlacerType = new NamePlacerGqlType());
    }

    public static function synonymMoverType(){
        return self::$synonymMoverType ?: (self::$synonymMoverType = new SynonymMoverGqlType());
    }
    
    public static function childMoverType(){
        return self::$childMoverType ?: (self::$childMoverType = new ChildMoverGqlType());
    }

    public static function unplacedFinderType(){
        return self::$unplacedFinderType ?: (self::$unplacedFinderType = new UnplacedFinderGqlType());
    }

    public static function basionymFinderType(){
        return self::$basionymFinderType ?: (self::$basionymFinderType = new BasionymFinderGqlType());
    }

    public static function identifierType(){
        return self::$identifierType ?: (self::$identifierType = new IdentifierGqlType());
    }

    public static function userType(){
        return self::$userType ?: (self::$userType = new UserGqlType());
    }

    public static function downloadFileType(){
        return self::$downloadFileType ?: (self::$downloadFileType = new DownloadFileGqlType());
    }

    public static function statsBasicSummaryType(){
        return self::$statsBasicSummaryType ?: (self::$statsBasicSummaryType = new StatsBasicSummaryGqlType());
    }

    public static function referenceType(){
        return self::$referenceType ?: (self::$referenceType = new ReferenceGqlType());
    }

    public static function referenceUsageType(){
        return self::$referenceUsageType ?: (self::$referenceUsageType = new ReferenceUsageGqlType());
    }

    public static function authorTeamMemberType(){
        return self::$authorTeamMemberType ?: (self::$authorTeamMemberType = new AuthorTeamMemberGqlType());
    }

    public static function getPlacementActionEnum(){

        if(!self::$placementActionEnum){

            self::$placementActionEnum = new EnumType([
                'name' => 'PlacementAction',
                'description' => 'One of the placement actions that can be taken on a name.',
                'values' => NamePlacer::$ACTION_TYPES
            ]);

        }
        return self::$placementActionEnum;
    } 

}