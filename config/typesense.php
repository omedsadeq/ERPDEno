<?php
/**
 * Typesense Search Engine Configuration
 */

define('TYPESENSE_HOST',                 getenv('TYPESENSE_HOST')     ?: 'localhost');
define('TYPESENSE_PORT',                 (int)(getenv('TYPESENSE_PORT') ?: 8108));
define('TYPESENSE_PROTOCOL',             getenv('TYPESENSE_PROTOCOL') ?: 'http');
define('TYPESENSE_API_KEY',              getenv('TYPESENSE_API_KEY')  ?: 'dental-clinic-typesense-key');
define('TYPESENSE_PATIENTS_COLLECTION',  'patients');
define('TYPESENSE_VISITS_COLLECTION',    'visits');
