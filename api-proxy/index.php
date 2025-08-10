<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Konfiguration
$kimaiUrl = 'server'; 
$apiToken = 'token';   

// HTTP-Client für Kimai
$kimaiClient = new Client([
    'base_uri' => $kimaiUrl,
    'verify' => false,
    'headers' => [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $apiToken,
    ],
]);

// Feiertage abrufen für eine spezifische Gruppe
function getGermanHolidaysForGroup($year, $groupId) {
    try {
        $client = new Client(['verify' => false]);
        $response = $client->get("https://feiertage-api.de/api/?jahr=$year&states=by,nw,sn,th");
        $allHolidays = json_decode($response->getBody(), true);
        
        // Zuordnung von public_holiday_group zu Bundesland
        $groupToState = [
            '4' => 'TH', // Thüringen
            '5' => 'ST', // Sachsen-Anhalt
            '6' => 'NW', // NRW
            '7' => 'BY'  // Bayern
        ];
        
        // Nur für das spezifische Bundesland relevante Feiertage
        $relevantHolidays = [];
        
        // Wenn keine gültige Gruppe angegeben ist, keine Feiertage zurückgeben
        if (!isset($groupId) || !isset($groupToState[$groupId])) {
            return $relevantHolidays;
        }
        
        $state = $groupToState[$groupId];
        
        // Bundesweite Feiertage aus BUND
        if (isset($allHolidays['BUND'])) {
            foreach ($allHolidays['BUND'] as $holidayName => $holidayData) {
                $relevantHolidays[$holidayData['datum']] = [
                    'name' => $holidayName,
                    'state' => 'BUND'
                ];
            }
        }
        
        // Spezifische Bundesland-Feiertage hinzufügen
        if (isset($allHolidays[$state])) {
            foreach ($allHolidays[$state] as $holidayName => $holidayData) {
                $relevantHolidays[$holidayData['datum']] = [
                    'name' => $holidayName,
                    'state' => $state
                ];
            }
        }
        
        return $relevantHolidays;
        
    } catch (Exception $e) {
        return [];
    }
}

// Aktuelles Jahr
$currentYear = date('Y');

// Benutzer abrufen 
try {
    $response = $kimaiClient->get('/api/users');
    $users = json_decode($response->getBody(), true);
} catch (RequestException $e) {
    die(json_encode(["error" => "Fehler beim Laden der Benutzer: " . $e->getMessage()]));
}

$januaryDataForGrafana = []; 
$startDate = (new DateTime("$currentYear-$month-01"))->setTime(0, 0, 0);
$endDate = (clone $startDate)->modify('last day of this month')->setTime(23, 59, 59);

foreach ($users as $user) {
    // Benutzer-Profil mit Feiertagsgruppe abrufen
    try {
        $userResponse = $kimaiClient->get('/api/users/' . $user['id']);
        $userProfile = json_decode($userResponse->getBody(), true);
        
        // Feiertagsgruppe aus den Benutzereinstellungen lesen
        $holidayGroup = null;
        if (isset($userProfile['preferences']) && is_array($userProfile['preferences'])) {
            foreach ($userProfile['preferences'] as $pref) {
                if ($pref['name'] === 'public_holiday_group') {
                    $holidayGroup = $pref['value'];
                    break;
                }
            }
        }
        
        // Feiertage für diese Gruppe abrufen
        $userHolidays = getGermanHolidaysForGroup($currentYear, $holidayGroup);
        
    } catch (RequestException $e) {
        $userHolidays = [];
    }
    
    // Hier ist die Änderung: Verwende 'alias' falls vorhanden, sonst 'username'
    $displayName = isset($user['alias']) && !empty($user['alias']) ? $user['alias'] : $user['username'];
    
    $userRow = ["Mitarbeiter" => $displayName]; // Erste Spalte mit Benutzernamen
    
    try {
        $response = $kimaiClient->get('/api/absences', [
            'query' => [
                'start' => $startDate->format('Y-m-d\TH:i:s'),
                'end' => $endDate->format('Y-m-d\TH:i:s'),
                'user' => $user['id']
            ]
        ]);
        $absences = json_decode($response->getBody(), true);
    } catch (RequestException $e) {
        $absences = [];
    }
    
    $currentDate = clone $startDate;
    while ($currentDate <= $endDate) {
        $dateString = $currentDate->format('d'); // 01-31 für die Spaltennamen
        $fullDateString = $currentDate->format('Y-m-d'); 
        $absenceType = null;
        
        if (isset($userHolidays[$fullDateString])) {
            // Markiere Feiertage mit 'F' wie angefordert
            $absenceType = "F";
        } elseif ($currentDate->format('N') >= 6) { // 6 = Samstag, 7 = Sonntag
            $absenceType = "Wochenende";
        } else {
            foreach ($absences as $absence) {
                if ((new DateTime($absence['date']))->format('Y-m-d') === $fullDateString) {
                    $absenceType = $absence['type']; 
                    break;
                }
            }
        }
        
        $userRow[$dateString] = $absenceType ?: ""; 
        $currentDate->modify('+1 day');
    }
    
    $januaryDataForGrafana[] = $userRow; 
}

header('Content-Type: application/json'); 
echo json_encode($januaryDataForGrafana, JSON_PRETTY_PRINT);