<?php

namespace App\Doctrine;

use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;

/**
 * Custom Naming Strategy for B-Prefix (BUSER, BMESSAGES, etc.)
 * 
 * Maps Entity-Names and Properties to Tables/Columns with B-Prefix:
 * - User → BUSER
 * - Message → BMESSAGES
 * - id → BID
 * - email → BMAIL
 */
class CustomNamingStrategy extends UnderscoreNamingStrategy
{
    public function classToTableName(string $className): string
    {
       
        $shortName = substr($className, strrpos($className, '\\') + 1);
        
     
        if (str_ends_with($shortName, 'Entity')) {
            $shortName = substr($shortName, 0, -6);
        }
        
      
        $pluralMap = [
            'Message' => 'MESSAGES',
            'Prompt' => 'PROMPTS',
            'Model' => 'MODELS',
            'Token' => 'TOKENS',
            'Session' => 'SESSIONS',
            'Payment' => 'PAYMENTS',
            'Subscription' => 'SUBSCRIPTIONS',
        ];
        
        if (isset($pluralMap[$shortName])) {
            return 'B' . $pluralMap[$shortName];
        }
        
        // Default: CamelCase zu UPPERCASE
        $tableName = strtoupper($this->underscore($shortName));
        
        // Füge B-Prefix hinzu
        if (!str_starts_with($tableName, 'B')) {
            $tableName = 'B' . $tableName;
        }
        
        return str_replace('_', '', $tableName);
    }

    public function propertyToColumnName(string $propertyName, ?string $className = null): string
    {
        // Spezielle Mappings
        $columnMap = [
            'id' => 'BID',
            'email' => 'BMAIL',
            'password' => 'BPW',
            'created' => 'BCREATED',
            'updated' => 'BUPDATED',
            'userId' => 'BUSERID',
            'text' => 'BTEXT',
        ];
        
        if (isset($columnMap[$propertyName])) {
            return $columnMap[$propertyName];
        }
        
        // Default: camelCase zu BUPPERCASE
        $columnName = strtoupper($this->underscore($propertyName));
        $columnName = str_replace('_', '', $columnName); // Entferne Underscores
        
        // Füge B-Prefix hinzu
        if (!str_starts_with($columnName, 'B')) {
            $columnName = 'B' . $columnName;
        }
        
        return $columnName;
    }
    
    private function underscore(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }
}

