<?php
/**
 * GoogleCalendarHelper - Manages Google Calendar API interactions.
 * Handles token refresh, event creation, and sync operations via REST API.
 */
class GoogleCalendarHelper {

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Get a valid access token for a user, refreshing if expired.
     */
    public function getValidAccessToken($userId) {
        $stmt = $this->db->prepare("SELECT google_access_token, google_refresh_token, google_token_expires_at FROM USERS WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['google_refresh_token'])) {
            return null;
        }

        $expiresAt = (int)($user['google_token_expires_at'] ?? 0);

        // If token is still valid (with 60s buffer), return it
        if (!empty($user['google_access_token']) && $expiresAt > (time() + 60)) {
            return $user['google_access_token'];
        }

        // Refresh the token
        return $this->refreshAccessToken($userId, $user['google_refresh_token']);
    }

    /**
     * Refresh an OAuth access token using the refresh token.
     */
    private function refreshAccessToken($userId, $refreshToken) {
        require_once(__DIR__ . '/../config/google_oauth.php');

        if (!isGoogleOAuthConfigured()) {
            return null;
        }

        $config = getGoogleOAuthConfig();

        $data = [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $response = $this->httpPost('https://oauth2.googleapis.com/token', $data);

        if (!$response || empty($response['access_token'])) {
            return null;
        }

        $newAccessToken = $response['access_token'];
        $expiresIn = (int)($response['expires_in'] ?? 3600);
        $newExpiresAt = time() + $expiresIn;

        $stmt = $this->db->prepare("UPDATE USERS SET google_access_token = ?, google_token_expires_at = ? WHERE id = ?");
        $stmt->execute([$newAccessToken, $newExpiresAt, (int)$userId]);

        return $newAccessToken;
    }

    /**
     * Create a Google Calendar event from an application event.
     */
    public function createCalendarEvent($accessToken, $eventData) {
        $calendarEvent = [
            'summary' => $eventData['titre'],
            'description' => ($eventData['description'] ?? '') . "\n\n[IIT-Club-Event-ID: " . (int)$eventData['id'] . "]",
            'location' => $eventData['lieu'] ?? '',
            'start' => [
                'dateTime' => date('c', strtotime($eventData['date_debut'])),
                'timeZone' => 'Africa/Algiers',
            ],
            'end' => [
                'dateTime' => date('c', strtotime($eventData['date_fin'])),
                'timeZone' => 'Africa/Algiers',
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 30],
                ],
            ],
        ];

        return $this->httpPostJson(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events',
            $calendarEvent,
            $accessToken
        );
    }

    /**
     * Check if an event with the given IIT event ID already exists.
     */
    public function findExistingCalendarEvent($accessToken, $iitEventId) {
        $query = urlencode('[IIT-Club-Event-ID: ' . (int)$iitEventId . ']');
        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events?q=' . $query . '&maxResults=5';

        $response = $this->httpGet($url, $accessToken);

        if ($response && !empty($response['items'])) {
            foreach ($response['items'] as $item) {
                if (isset($item['description']) && strpos($item['description'], '[IIT-Club-Event-ID: ' . (int)$iitEventId . ']') !== false) {
                    return $item['id'];
                }
            }
        }

        return null;
    }

    /**
     * Update an existing Google Calendar event.
     */
    public function updateCalendarEvent($accessToken, $googleEventId, $eventData) {
        $calendarEvent = [
            'summary' => $eventData['titre'],
            'description' => ($eventData['description'] ?? '') . "\n\n[IIT-Club-Event-ID: " . (int)$eventData['id'] . "]",
            'location' => $eventData['lieu'] ?? '',
            'start' => [
                'dateTime' => date('c', strtotime($eventData['date_debut'])),
                'timeZone' => 'Africa/Algiers',
            ],
            'end' => [
                'dateTime' => date('c', strtotime($eventData['date_fin'])),
                'timeZone' => 'Africa/Algiers',
            ],
        ];

        return $this->httpPutJson(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . urlencode($googleEventId),
            $calendarEvent,
            $accessToken
        );
    }

    /**
     * Sync all approved/closed events for a user to their Google Calendar.
     * Returns an array with sync results.
     */
    public function syncUserEvents($userId) {
        $accessToken = $this->getValidAccessToken($userId);
        if (!$accessToken) {
            return ['success' => false, 'error' => 'Impossible de se connecter à Google Calendar. Reconnectez votre compte.'];
        }

        // Get all events user is a participant of, or all approved events for admins/responsables
        $stmt = $this->db->prepare("SELECT e.*, c.nom AS club_nom
                                    FROM EVENTS e
                                    JOIN CLUBS c ON c.id = e.club_id
                                    WHERE e.approval_status IN ('approved', 'closed')
                                    AND e.date_debut >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
                                    ORDER BY e.date_debut ASC");
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        $updated = 0;
        $errors = 0;

        foreach ($events as $event) {
            $existingId = $this->findExistingCalendarEvent($accessToken, (int)$event['id']);

            if ($existingId) {
                $result = $this->updateCalendarEvent($accessToken, $existingId, $event);
                if ($result) {
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                $result = $this->createCalendarEvent($accessToken, $event);
                if ($result) {
                    $created++;
                } else {
                    $errors++;
                }
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($events),
        ];
    }

    // --- HTTP helper methods ---

    private function httpPost($url, $data) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response !== false ? json_decode($response, true) : null;
    }

    private function httpPostJson($url, $data, $accessToken) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
            return json_decode($response, true);
        }
        return null;
    }

    private function httpPutJson($url, $data, $accessToken) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
            return json_decode($response, true);
        }
        return null;
    }

    private function httpGet($url, $accessToken) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
            return json_decode($response, true);
        }
        return null;
    }
}
?>
