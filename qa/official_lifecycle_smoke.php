<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/official/lifecycle_helpers.php';
$base=['severity'=>'yellow','ends_at'=>'2026-09-09T18:00:00Z','content_hash'=>'same'];
$checks=[
 'escalated'=>meteonexa_official_change_type($base,'orange','2026-09-09T18:00:00Z','same'),
 'extended'=>meteonexa_official_change_type($base,'yellow','2026-09-09T23:00:00Z','same'),
 'downgraded'=>meteonexa_official_change_type(['severity'=>'orange','ends_at'=>$base['ends_at'],'content_hash'=>'same'],'yellow',$base['ends_at'],'same'),
 'shortened'=>meteonexa_official_change_type($base,'yellow','2026-09-09T15:00:00Z','same'),
 'updated'=>meteonexa_official_change_type($base,'yellow',$base['ends_at'],'changed'),
 'unchanged'=>meteonexa_official_change_type($base,'yellow',$base['ends_at'],'same'),
];
foreach($checks as $expected=>$actual)if($actual!==$expected)throw new RuntimeException("$expected:$actual");
echo "Official lifecycle smoke PASS\n";
