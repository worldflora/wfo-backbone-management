<?php

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;

class TaxonGqlType extends ObjectType
{
    public function __construct()
    {
        $config = [
            'description' => "Description of a Taxon",
            'fields' => function() {
                return [
                    'id' => [
                        'type' => Type::int(),
                        'description' => "database id for the taxon",
                        'resolve' => function($taxon){
                            return $taxon->getId();
                        }
                    ],
                    'acceptedName' => [
                        'type' => TypeRegister::nameType(),
                        'description' => "The accepted name for this taxon",
                        'resolve' => function($taxon){
                            return $taxon->getAcceptedName();
                        }
                    ],
                    'fullNameString' => [
                        'type' => Type::string(),
                        'description' => "The full name string of the accepted name of the taxon with hybrid markings if appropriate.",
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
                        'resolve' => function($taxon, $args, $context, $info){
                            return $taxon->getFullNameString( $args['italics'], $args['authors'], $args['abbreviateRank'], $args['abbreviateGenus'] );
                        }
                    ],
                    'isHybrid' => [
                        'type' => Type::boolean(),
                        'description' => "Whether this taxon is a hybrid taxon or not.",
                        'resolve' => function($taxon){
                            return $taxon->getHybridStatus();
                        }
                    ],
                    'isFossil' => [
                        'type' => Type::boolean(),
                        'description' => "Whether this taxon is a fossil taxon or not.",
                        'resolve' => function($taxon){
                            return $taxon->getFossilStatus();
                        }
                    ],
                    'canEdit' => [
                        'type' => Type::boolean(),
                        'description' => "Whether the current user has permission to edit this taxon.",
                        'resolve' => function($taxon){
                            return $taxon->canEdit();
                        }
                    ],
                    'synonyms' => [
                        'type' => Type::listOf(TypeRegister::nameType()),
                        'description' => "The names that are considered synonyms of this taxon.",
                        'resolve' => function($taxon){
                            return $taxon->getSynonyms();
                        }
                    ],
                    'children' => [
                        'type' => Type::listOf(TypeRegister::taxonType()),
                        'description' => "The taxa that are part of this taxon.",
                        'resolve' => function($taxon){
                            return $taxon->getChildren();
                        }
                    ],
                    'ancestors' => [
                        'type' => Type::listOf(TypeRegister::taxonType()),
                        'description' => "This taxon all the way back to the root.",
                        'resolve' => function($taxon){
                            return $taxon->getAncestors();
                        }
                    ],
                    'ancestorAtRank' => [
                        'type' => TypeRegister::taxonType(),
                        'description' => "Looks up the ancestor lineage and returns the ancestor with the specified rank or null if one isn't found.",
                        'args' => [
                            'rank' => [
                                'type' => Type::string(),
                                'description' => "The rank of interest.",
                                'required' => true
                            ]
                        ],
                        'resolve' => function($taxon, $args, $context, $info){
                            return $taxon->getAncestorAtRank($args['rank']);
                        }
                    ],
                    'parent' => [
                        'type' => TypeRegister::taxonType(),
                        'description' => "The taxon that contains this taxon.",
                        'resolve' => function($taxon){
                            return $taxon->getParent();
                        }
                    ],
                    'rank' => [
                        'type' => TypeRegister::rankType(),
                        'description' => "The rank of this taxon. This is a wrapper around the rank of the accepted name.",
                        'resolve' => function($taxon){
                            return Rank::getRank($taxon->getRank());
                        }
                    ],
                    'references' => [
                        'type' => Type::listOf(TypeRegister::referenceUsageType()),
                        'description' => "The references associated with this taxon - literature only.",
                        'resolve' => function($taxon){
                            return $taxon->getReferences('literature');
                        }
                    ]

/*
                    'descriptionMarkDown' => [
                        'type' => Type::string(),
                        'description' => "A description of this taxon in Mark Down format",
                        'resolve' => function($taxon){
                            // The description is actually stored in the name object
                            // this is so it persists if the name is unplaced and then
                            // placed again as a new taxon.
                            // FIXME
                            return 'A description';
                            //return $taxon->getReferences('literature');
                        }
                    ],
                    'descriptionComplete' => [
                        'type' => Type::int(),
                        'description' => "A flag to indicate that the description is complete and can be published.",
                        'resolve' => function($taxon){
                            // The description is actually stored in the name object
                            // this is so it persists if the name is unplaced and then
                            // placed again as a new taxon.
                            // FIXME
                            return true;
                            //return $taxon->getReferences('literature');
                        }
                    ]
*/

                ];
            }
        ];
        parent::__construct($config);

    }

}