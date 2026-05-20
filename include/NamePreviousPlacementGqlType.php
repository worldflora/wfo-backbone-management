<?php

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;

class NamePreviousPlacementGqlType extends ObjectType
{
    public function __construct()
    {
        $config = [
            'description' => "A placement change for a name in a previous classification.",
            'fields' => function() {
                return [
                    'id' => [
                        'type' => Type::int(),
                        'description' => "ID for this placement"
                    ],
                    'name' => [
                        'type' => TypeRegister::nameType(),
                        'description' => "The name that is being placed"
                    ],
                    'placedInName' => [
                        'type' => TypeRegister::nameType(),
                        'description' => "The accepted name of the taxon the name was placed in, as the accepted name or a synonym."
                    ],
                    'classificationMonthName' => [
                        'type' => Type::string(),
                        'description' => "The month of the classification"
                    ],
                    'classificationYear' => [
                        'type' => Type::int(),
                        'description' => "The year of the classification"
                    ],
                    'classificationString' => [
                        'type' => Type::string(),
                        'description' => "The classification name as a string"
                    ],
                    'newRole' => [
                        'type' => Type::string(),
                        'description' => "The role this name now plays in the classification."
                    ]
                ];
            }
        ];
        parent::__construct($config);
    
    } // constructor

}// class