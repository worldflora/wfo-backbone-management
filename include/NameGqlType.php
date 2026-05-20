<?php

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;

class NameGqlType extends ObjectType
{
    public function __construct()
    {
        $config = [
            'description' => "A Name governed by the the code",
            'fields' => function() {
                return [
                    'id' => [
                        'type' => Type::int(),
                        'description' => "database id for the name",
                        'resolve' => function($name){
                            return $name->getId();
                        }
                    ],
                    'wfo' => [
                        'type' => Type::string(),
                        'description' => "The prescribed WFO ID to use for this name",
                        'resolve' => function($name){
                            return $name->getPrescribedWfoId();
                        }
                    ],
                    'ipni' => [
                        'type' => Type::string(),
                        'description' => "The preferred IPNI identifier associated with this name.",
                        'resolve' => function($name){
                            return $name->getPreferredIpniId();
                        }
                    ],
                    'identifiers' => [
                        'type' => Type::listOf(TypeRegister::identifierType()),
                        'description' => "A list of know identifiers (excluding the db on) for this name.",
                        'resolve' => function($name){
                            return $name->getIdentifiers();
                        }
                    ],
                    'rank' => [
                        'type' => TypeRegister::rankType(),
                        'description' => "The rank string for this name",
                        'resolve' => function($name){
                            return Rank::getRank($name->getRank());
                        }
                    ],
                    'nameString' => [
                        'type' => Type::string(),
                        'description' => "The principle name string for this name.",
                        'resolve' => function($name){
                            return $name->getNameString();
                        }
                    ],
                    'genusString' => [
                        'type' => Type::string(),
                        'description' => "The genus name string for names below the rank of genus.",
                        'resolve' => function($name){
                            return $name->getGenusString();
                        }
                    ],
                    'speciesString' => [
                        'type' => Type::string(),
                        'description' => "The species name string for names below the rank of species.",
                        'resolve' => function($name){
                            return $name->getSpeciesString();
                        }
                    ],
                    'authorsString' => [
                        'type' => Type::string(),
                        'description' => "The string representing the author abbreviations for this name.",
                        'resolve' => function($name){
                            return $name->getAuthorsString();
                        }
                    ],
                    'fullNameString' => [
                        'type' => Type::string(),
                        'description' => "The full name for display purposes.",
                        'args' => [
                            'italics' => [
                                'type' => Type::boolean(),
                                'description' => "Whether words in names at and below the genus level should be italicized. Defaults true.",
                                'required' => false,
                                'defaultValue' => true
                            ],
                            'authors' => [
                                'type' => Type::boolean(),
                                'description' => "Whether the authors string (abbreviated authors) should be included. Defaults true.",
                                'required' => false,
                                'defaultValue' => true
                            ],
                            'abbreviateRank' => [
                                'type' => Type::boolean(),
                                'description' => "Whether the rank (always included in names below genus) should be abbreviated. Defaults true.",
                                'required' => false,
                                'defaultValue' => true
                            ],
                            'abbreviateGenus' => [
                                'type' => Type::boolean(),
                                'description' => "Whether the genus word (in names below the rank of genus) should be abbreviated. Defaults false.",
                                'required' => false,
                                'defaultValue' => false
                            ],
                        ],
                        'resolve' => function($name, $args, $context, $info){
                            return $name->getFullNameString( $args['italics'], $args['authors'], $args['abbreviateRank'], $args['abbreviateGenus'] );
                        }
                    ],
                    'status' => [
                        'type' => Type::string(),
                        'description' => "The nomenclatural status of this name.",
                        'resolve' => function($name){
                            return $name->getStatus();
                        }
                    ],
                    'isAutonym' => [
                        'type' => Type::boolean(),
                        'description' => "True if this is an autonym name.",
                        'resolve' => function($name){
                            return $name->isAutonym();
                        }
                    ],
                    'basionym' => [
                        'type' => TypeRegister::nameType(),
                        'description' => "The type bearing name for this name.",
                        'resolve' => function($name){
                            return $name->getBasionym();
                        }
                    ],
                    'homotypicNames' => [
                        'type' => Type::listOf(TypeRegister::nameType()),
                        'description' => "Names that share the same type as this name.",
                        'resolve' => function($name){
                            return $name->getHomotypicNames();
                        }
                    ],
                    'homonyms' => [
                        'type' => Type::listOf(TypeRegister::nameType()),
                        'description' => "Names that are homonyms of this name i.e. have the same name parts.",
                        'resolve' => function($name){
                            return $name->getHomonyms();
                        }
                    ],
                    'citationMicro' => [
                        'type' => Type::string(),
                        'description' => "The standard form short citation for where this name was published.",
                        'resolve' => function($name){
                            return $name->getCitationMicro();
                        }
                    ],
                    'citationId' => [
                        'type' => Type::string(),
                        'description' => "An identifier for the citation of where this name was published.",
                        'resolve' => function($name){
                            return $name->getCitationID();
                        }
                    ],
                    'comment' => [
                        'type' => Type::string(),
                        'description' => "A comment on the name",
                        'resolve' => function($name){
                            return $name->getComment();
                        }
                    ],
                    'year' => [
                        'type' => Type::int(),
                        'description' => "The year of valid publication of this name",
                        'resolve' => function($name){
                            return $name->getYear();
                        }
                    ],

                    // note that strictly Names know nothing of Taxa but because this is an
                    // API interface is seems OK to join them up here - but never in the Name object!!
                    'taxonPlacement' => [
                        'type' => TypeRegister::taxonType(),
                        'description' => "The taxon for which this is either the accepted name or a synonym. Null if the name hasn't been placed in the taxonomy.",
                        'resolve' => function($name) {

                            $taxon = Taxon::getTaxonForName($name);

                            // a blank taxon is created if the name is not placed (it is how we create new ones)
                            // and we don't want to return that for a query
                            if($taxon->getId()){
                                return $taxon;
                            }else{
                                return null;
                            }

                        }
                    ],

                    'canEdit' => [
                        'type' => Type::boolean(),
                        'description' => "Whether the current user has authority to edit this name or not.",
                        'resolve' => function($name) {
                            return $name->canEdit();
                        }
                    ],

                    'isCurator' => [
                        'type' => Type::boolean(),
                        'description' => "Whether the current user is a curator of the taxon this name is associated with.",
                        'resolve' => function($name) {
                            $taxon = Taxon::getTaxonForName($name);
                            $user = unserialize($_SESSION['user']);
                            return $taxon->isCurator($user);
                        }
                    ],

                    'editors' => [
                        'type' => Type::listOf(TypeRegister::userType()),
                        'description' => "The list of users who can edit this name because they are a curator of the taxon it belongs to or one of the taxons ancestors. An empty list is returned for unplaced names because any editor can edit these.",
                        'resolve' => function($name) {
                            $taxon = Taxon::getTaxonForName($name);
                            return $taxon->getEditors();
                        }
                    ],

                    'lastEditor' => [
                        'type' => TypeRegister::userType(),
                        'description' => "The last user to have edited this name (including placed it somewhere)",
                        'resolve' => function($name) {
                            return $name->getUser();
                        }
                    ],

                    'lastModified' => [
                        'type' => Type::int(),
                        'description' => "The date and time of the last modification as a unix timestamp",
                        'resolve' => function($name) {
                            $m = new DateTime($name->getModified());
                            return  $m->getTimeStamp();
                        }
                    ],

                    'lastChangeMessage'  => [
                        'type' => Type::string(),
                        'description' => "The log message from the last time this name was changed.",
                        'resolve' => function($name) {
                            return  $name->getChangeLog();
                        }
                    ],

                    'curators' => [
                        'type' => Type::listOf(TypeRegister::userType()),
                        'description' => "The list of users who are a curators of the taxon. A subset of editors. An empty list is returned for unplaced names because any editor can edit these.",
                        'resolve' => function($name) {
                            $taxon = Taxon::getTaxonForName($name);
                            return $taxon->getCurators();
                        }
                    ],
                    'references' => [
                        'type' => Type::listOf(TypeRegister::referenceUsageType()),
                        'description' => "The references associated with this name.",
                        'args' => [
                            'kind' => [
                                'type' => Type::string(),
                                'description' => "Restrict to one kind of reference (person, literature, specimen) or leave null for all of them.",
                                'required' => false,
                                'defaultValue' => null
                            ]
                        ],
                        'resolve' => function($name, $args){
                            return $name->getReferences($args['kind']);
                        }
                    ],
                    'gbifOccurrenceCount' => [
                        'type' => Type::int(),
                        'description' => "The number of occurrences in GBIF for this name. Initially this is only available for unplaced species and will return null for other names.",
                        'resolve' => function($name){
                            return $name->getGbifOccurrenceCount();
                        }
                    ],

                    'ipniDifferences' => [
                        'type' => TypeRegister::nameIpniDifferencesType(),
                        'description' => "The difference between this name and the IPNI record associated with it. Called live. MAY BE SLOW!",
                        'resolve' => function($name){
                            return new NameIpniDifferences($name);
                        }
                    ],

                    'previousPlacements' => [
                        'type' => Type::listOf(TypeRegister::namePreviousPlacementType()),
                        'description' => "Placements of this name in previous classifications",
                        'resolve' => function($name){
                            return $name->getPreviousPlacements();
                        }
                    ]
                ];
            }
        ];
        parent::__construct($config);

    }

}