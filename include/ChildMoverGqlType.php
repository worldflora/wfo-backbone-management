<?php

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\EnumType;


class ChildMoverGqlType extends ObjectType
{

    public function __construct()
    {
        $config = [
            'description' => "A mechanism for moving all the child taxa of a taxon.",
            'fields' => function() {
                // this is a very simple wrapper around an
                // object with a few public properties.
                return [
                    'hasMovableChildren' => [
                        'type' => Type::boolean(),
                        'description' => "Does this taxon have children that can be moved without changing their names",
                        'resolve' => function($mover){
                            return $mover->hasMovableChildren();
                        }
                    ],
                    'possibleParentRanks' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => "A list of the ranks the children could all be placed in.",
                        'resolve' => function($mover){
                            return $mover->getPossibleRanks();
                        }
                    ],
                    'requiredGenusString' => [
                        'type' => Type::string(),
                        'description' => "The new parent must be this genus or have this genus string."
                    ],
                    'requiredSpeciesString' => [
                        'type' => Type::string(),
                        'description' => "The new parent must be this species string or be this species."
                    ],
                    'filter' => [
                        'type' => Type::string(),
                        'description' => "The text used to filter the possibleTaxa returned. Initially the first few letters of the name_alpha"
                    ],
                    'possibleTaxa' => [
                        'type' => Type::listOf(TypeRegister::taxonType()),
                        'description' => "A list of taxa that the taxa could be moved to, restricted to first 100 alphabetically and by filter applied.",
                        'resolve' => function($mover){
                            return $mover->getPossibleTaxa();
                        }
                    ]
                ];
            }
        ];
        parent::__construct($config);
    }
}