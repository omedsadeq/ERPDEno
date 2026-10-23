<?php
/**
 * ERP — customers. ERP customers are their own records, never the dental
 * patients table; a customer may optionally be linked to a patient later.
 * The screen itself lives in _party_page.php, shared with suppliers.
 */
$ERP_PARTY_KIND = 'customer';
require __DIR__ . '/_party_page.php';
