<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Grocy\Helpers\WebhookRunner;
use Grocy\Helpers\Grocycode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RecipesApiController extends BaseApiController
{
	public function AddNotFulfilledProductsToShoppingList(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);
		$excludedProductIds = null;

		if ($requestBody !== null && array_key_exists('excludedProductIds', $requestBody))
		{
			$excludedProductIds = $requestBody['excludedProductIds'];
		}

		$this->getRecipesService()->AddNotFulfilledProductsToShoppingList($args['recipeId'], $excludedProductIds);
		return $this->EmptyApiResponse($response);
	}

	public function ConsumeRecipe(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_CONSUME);

		try
		{
			$this->getRecipesService()->ConsumeRecipe($args['recipeId']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function GetRecipeFulfillment(Request $request, Response $response, array $args)
	{
		try
		{
			if (!isset($args['recipeId']))
			{
				return $this->FilteredApiResponse($response, $this->getRecipesService()->GetRecipesResolved(), $request->getQueryParams());
			}

			$recipeResolved = FindObjectInArrayByPropertyValue($this->getRecipesService()->GetRecipesResolved(), 'recipe_id', $args['recipeId']);

			if (!$recipeResolved)
			{
				throw new \Exception('Recipe does not exist');
			}
			else
			{
				return $this->ApiResponse($response, $recipeResolved);
			}
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function CopyRecipe(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, [
				'created_object_id' => $this->getRecipesService()->CopyRecipe($args['recipeId'])
			]);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function RecipePrintLabel(Request $request, Response $response, array $args)
	{
		try
		{
			$recipe = $this->getDatabase()->recipes()->where('id', $args['recipeId'])->fetch();

			$webhookData = array_merge([
				'recipe' => $recipe->name,
				'grocycode' => (string)(new Grocycode(Grocycode::RECIPE, $args['recipeId'])),
			], GROCY_LABEL_PRINTER_PARAMS);

			if (GROCY_LABEL_PRINTER_RUN_SERVER)
			{
				(new WebhookRunner())->run(GROCY_LABEL_PRINTER_WEBHOOK, $webhookData, GROCY_LABEL_PRINTER_HOOK_JSON);
			}

			return $this->ApiResponse($response, $webhookData);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function ImportFromHtml(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_RECIPES);

		$logFilePath = null;
		$logMessages = [];

		try
		{
			$logFilePath = $this->_prepareLogFile($logMessages);
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			if (!isset($requestBody['html']) || empty($requestBody['html']))
			{
				throw new \Exception('HTML content is required');
			}
			$this->_logMessage("HTML-Content empfangen (erste 100 Zeichen): " . substr($requestBody['html'], 0, 100) . "...", $logMessages);

			$apiKey = $this->_getApiKey($requestBody);
			$this->_logMessage("API-Key vorhanden: Ja", $logMessages);

			// Fetch existing product names with id
			$existingProducts = $this->getDatabase()->products()->where('active = ?', 1)->select('id, name')->fetchAll();
			$existingProductNames = array_map(function($p) { return $p->name; }, $existingProducts);
			$this->_logMessage("Anzahl existierender Produkte gefunden: " . count($existingProductNames), $logMessages);

			$recipeDataJson = $this->_getRecipeDataFromGemini($requestBody['html'], $apiKey, $existingProductNames, $logMessages);
			$recipeData = $this->_parseRecipeJson($recipeDataJson, $logMessages);

			$preparedRecipeData = $this->_validateAndPrepareRecipeData($recipeData, $logMessages);
			$newRecipeId = $this->_insertRecipe($preparedRecipeData, $logMessages);

			if (isset($recipeData['ingredients']) && is_array($recipeData['ingredients']))
			{
				$this->_processIngredients($newRecipeId, $recipeData['ingredients'], $apiKey, $logMessages);
			}
			else
			{
				$this->_logMessage("Keine Zutaten im JSON gefunden oder 'ingredients' ist kein Array.", $logMessages);
			}

			if (!empty($preparedRecipeData['picture_url']))
			{
				$this->_downloadAndAssignPicture($newRecipeId, $preparedRecipeData['picture_url'], $logMessages);
			}
			else
			{
				$this->_logMessage("Keine gültige Bild-URL in den Daten gefunden.", $logMessages);
			}

			$this->_logMessage("=== Ende des Debug-Logs ===", $logMessages);
			file_put_contents($logFilePath, implode("\n", $logMessages));

			return $this->ApiResponse($response, [
				'created_recipe_id' => $newRecipeId,
				'recipe' => $this->getDatabase()->recipes()->where('id = ?', $newRecipeId)->fetch(),
				'debug_log_path' => $logFilePath
			]);
		}
		catch (\Exception $ex)
		{
			return $this->_handleImportError($ex, $logFilePath, $logMessages, $response);
		}
	}

	// --- Private Helper Functions for ImportFromHtml ---

	private function _prepareLogFile(array &$logMessages): string
	{
		$logFileDir = defined('GROCY_DATAPATH') ? GROCY_DATAPATH . '/logs' : './logs';
		if (!file_exists($logFileDir)) {
			if (!mkdir($logFileDir, 0777, true) && !is_dir($logFileDir)) {
				error_log("Warnung: Konnte Log-Verzeichnis nicht erstellen: " . $logFileDir);
			}
		}
		$logFilePath = $logFileDir . '/recipe_import_' . time() . '.log';
		$logMessages[] = "=== Rezept-Import Debug Log ===";
		$logMessages[] = "Zeit: " . date('Y-m-d H:i:s');
		return $logFilePath;
	}

	private function _logMessage(string $message, array &$logMessages): void
	{
		$logMessages[] = $message;
	}

	private function _getApiKey(array $requestBody): string
	{
		$apiKey = isset($requestBody['api_key']) ? $requestBody['api_key'] : getenv('GEMINI_API_KEY');
		if (empty($apiKey))
		{
			throw new \Exception('Gemini API key is required');
		}
		return $apiKey;
	}

	private function _getRecipeDataFromGemini(string $htmlContent, string $apiKey, array $existingProductNames, array &$logMessages): string
	{
		$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
		$this->_logMessage("API-URL für Rezept-Extraktion: " . $apiUrl, $logMessages);

        $existingProductsString = implode(", ", array_map(function($name) { return '"' . addslashes($name) . '"'; }, $existingProductNames));
        if (empty($existingProductsString)) {
            $existingProductsString = "Keine";
        }
        $this->_logMessage("Existierende Produktnamen für Prompt (gekürzt): " . substr($existingProductsString, 0, 200) . "...", $logMessages);

		$prompt = <<<PROMPT
		Extrahiere Rezeptinformationen aus dem folgenden HTML-Inhalt. Gib die Ergebnisse ausschließlich als JSON-Objekt zurück, ohne umschließende Markdown-Formatierung (wie ```json ... ```). Das JSON sollte die folgende Struktur haben:
		{
			"name": "Rezeptname",
			"description": "Kurze Beschreibung des Rezepts als HTML-formatierter Text (optional). Verwende grundlegende HTML-Tags wie <p>, <b>, <i>, <ul>, <ol>, <li> für Struktur.",
			"base_servings": 4,
			"preparation": "Zubereitungsschritte als HTML-formatierter Text. Strukturiere die Schritte klar, z.B. mit <ol><li>...</li></ol>. Versuche, spezielle Symbole (z.B. Thermomix-Icons) aus dem Quelltext zu übernehmen oder durch Unicode/Text zu repräsentieren. Wenn YouTube-Videos verlinkt sind, bette sie als <iframe> ein oder füge einen <a>-Link hinzu.",
			"ingredients": [
				{
					"name": "Produktname (z.B. 'Ei' aus '1 Eigelb')",
					"amount": 1.0,
					"unit": "Stück",
					"note": "Zusätzliche Info (z.B. 'nur Eigelb', 'geschmolzen', kann null sein)",
					"ingredient_group": "Gruppe (z.B. 'Teig', 'Belag', kann null sein)"
				}
			],
			"picture_url": "URL zum Rezeptbild, falls verfügbar"
		}

		WICHTIG:
		- Die gesamte Ausgabe MUSS valides JSON sein.
		- Der HTML-Code muss korrekt escaped innerhalb der JSON-Strings für 'description' und 'preparation' stehen.
		- Bei Zutaten: Extrahiere den reinen Produktnamen (z.B. aus "1 großes Eigelb" wird "Ei").
		- Füge eine Notiz (`note`) hinzu, wenn die Zutat spezifiziert wurde (z.B. "nur Eigelb", "groß", "geschmolzen").
		- Wenn die Zutaten im HTML nach Gruppen (z.B. "Für den Teig", "Für den Belag") unterteilt sind, gib den Gruppennamen im Feld `ingredient_group` an, ansonsten `null`.
		- Ersetze Cookidoo/Thermomix Zeichen wie '' durch ein entsprechendes Emoji oder einem beschreibenden Text ('Kneten').
		- **Produkt-Matching:** Versuche, den extrahierten Produktnamen (`name` in `ingredients`) auf einen der folgenden bereits existierenden Produktnamen zu mappen, wenn er sehr ähnlich oder identisch ist. Gib den gematchten Namen zurück. Existierende Produkte: [$existingProductsString]. Wenn kein passendes Produkt gefunden wird, gib den extrahierten Namen zurück.

		HTML-Inhalt:
		$htmlContent
		PROMPT;

		$payload = [
			'contents' => [['parts' => [['text' => $prompt]]]],
			'generationConfig' => [
				'response_mime_type' => 'application/json',
				'temperature' => 0.2,
				'topK' => 32,
				'topP' => 0.95,
				'maxOutputTokens' => 8192
			]
		];

		$this->_logMessage("Payload für Rezept-API vorbereitet.", $logMessages);
		list($httpCode, $responseData, $curlError) = $this->_executeCurlRequest($apiUrl, $payload, $logMessages);

		if ($httpCode !== 200 || $responseData === false)
		{
			throw new \Exception('Fehler beim Aufruf der Gemini API (Rezept): HTTP ' . $httpCode . ' - ' . $curlError . ' - ' . $responseData);
		}

		$responseJson = json_decode($responseData, true);

		if (isset($responseJson['error'])) {
			throw new \Exception('Gemini API Fehler (Rezept): ' . $responseJson['error']['message']);
		}
		if (!isset($responseJson['candidates'][0]['content']['parts'][0]['text']))
		{
			if (isset($responseJson['promptFeedback']['blockReason'])) {
				throw new \Exception('Ungültige Antwort von Gemini API (Rezept): Anfrage blockiert. Grund: ' . $responseJson['promptFeedback']['blockReason'] . (isset($responseJson['promptFeedback']['safetyRatings']) ? ' - Details: ' . json_encode($responseJson['promptFeedback']['safetyRatings']) : ''));
			}
			throw new \Exception('Ungültige oder unerwartete Antwortstruktur von Gemini API (Rezept): ' . $responseData);
		}

		$recipeJsonText = $responseJson['candidates'][0]['content']['parts'][0]['text'];
		$this->_logMessage("Extrahierter JSON-Text (Rezept): " . substr($recipeJsonText, 0, 500) . "...", $logMessages);
		return $recipeJsonText;
	}

	private function _parseRecipeJson(string $recipeJson, array &$logMessages): array
	{
		$recipeData = json_decode($recipeJson, true);
		if (json_last_error() !== JSON_ERROR_NONE)
		{
			$cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', $recipeJson);
			$recipeData = json_decode($cleanedJson, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new \Exception('Konnte Rezeptdaten nicht als JSON parsen: ' . json_last_error_msg() . ' - Ursprünglicher Text: ' . substr($recipeJson, 0, 500) . "...");
			}
			$this->_logMessage("JSON (Rezept) erfolgreich geparst nach Bereinigung von Markdown.", $logMessages);
		} else {
			$this->_logMessage("JSON (Rezept) erfolgreich geparst.", $logMessages);
		}
		return $recipeData;
	}

	private function _validateAndPrepareRecipeData(array $recipeData, array &$logMessages): array
	{
		if (!isset($recipeData['name']) || empty(trim($recipeData['name']))) {
			throw new \Exception('Rezeptname fehlt in den extrahierten Daten.');
		}
		$recipeName = trim($recipeData['name']);

		$combinedDescription = '';
		if (isset($recipeData['description']) && is_string($recipeData['description']) && !empty(trim($recipeData['description']))) {
			$combinedDescription .= trim($recipeData['description']);
		}
		if (isset($recipeData['preparation']) && is_string($recipeData['preparation']) && !empty(trim($recipeData['preparation']))) {
			if (!empty($combinedDescription)) {
				$combinedDescription .= "\n\n--- Zubereitung ---\n\n";
			}
			$combinedDescription .= trim($recipeData['preparation']);
		}
		$recipeDescription = !empty($combinedDescription) ? $combinedDescription : null;

		$baseServings = isset($recipeData['base_servings']) && is_numeric($recipeData['base_servings']) && $recipeData['base_servings'] > 0 ? intval($recipeData['base_servings']) : 1;
		$desiredServings = $baseServings;

		$pictureUrl = isset($recipeData['picture_url']) && filter_var($recipeData['picture_url'], FILTER_VALIDATE_URL) ? $recipeData['picture_url'] : null;

		$this->_logMessage("Rezeptdaten validiert: Name='{$recipeName}', Portionen={$baseServings}", $logMessages);

		return [
			'name' => $recipeName,
			'description' => $recipeDescription,
			'base_servings' => $baseServings,
			'desired_servings' => $desiredServings,
			'picture_file_name' => null,
			'picture_url' => $pictureUrl
		];
	}

	private function _insertRecipe(array $preparedData, array &$logMessages): int
	{
		$this->_logMessage("Füge Rezept in die Datenbank ein: Name='{$preparedData['name']}', Portionen={$preparedData['base_servings']}", $logMessages);
		try {
			$statement = $this->getDatabaseService()->ExecuteDbStatement('INSERT INTO recipes
			   (name, description, base_servings, desired_servings, picture_file_name)
			   VALUES (:name, :description, :base_servings, :desired_servings, :picture_file_name)',
			   [
				   'name' => $preparedData['name'],
				   'description' => $preparedData['description'],
				   'base_servings' => $preparedData['base_servings'],
				   'desired_servings' => $preparedData['desired_servings'],
				   'picture_file_name' => $preparedData['picture_file_name']
			   ]
		   );

			$newRecipeId = $this->getDatabaseService()->GetDbConnectionRaw()->lastInsertId();
			if (empty($newRecipeId) || $newRecipeId == 0) {
				throw new \Exception('Fehler beim Einfügen des Rezepts: Keine gültige ID erhalten');
			}
			$this->_logMessage("Rezept erfolgreich eingefügt. Rezept-ID: " . $newRecipeId, $logMessages);
			return (int)$newRecipeId;
		} catch (\Exception $ex) {
			throw new \Exception('Fehler beim Erstellen des Rezepts in der Datenbank: ' . $ex->getMessage());
		}
	}

	private function _processIngredients(int $recipeId, array $ingredientsData, string $apiKey, array &$logMessages): void
	{
		$this->_logMessage("Verarbeite Zutaten...", $logMessages);
		$this->_logMessage("Anzahl der gefundenen Zutaten: " . count($ingredientsData), $logMessages);

		foreach ($ingredientsData as $index => $ingredient)
		{
			$ingredientLog = ["--- Verarbeite Zutat #" . ($index + 1) . " ---"];
			try {
				if (!isset($ingredient['name']) || empty(trim($ingredient['name'])) || !isset($ingredient['amount']) || !is_numeric($ingredient['amount'])) {
					$this->_logMessage("FEHLER bei Zutat #" . ($index + 1) . ": Ungültige oder fehlende Daten (Name/Menge). Überspringe Zutat: " . json_encode($ingredient), $logMessages);
					continue;
				}

				$ingredientName = trim($ingredient['name']);
				$ingredientAmount = floatval($ingredient['amount']);
				$ingredientUnitName = isset($ingredient['unit']) && !empty(trim($ingredient['unit'])) ? trim($ingredient['unit']) : 'Stück';
				$ingredientNote = isset($ingredient['note']) && !empty(trim($ingredient['note'])) ? trim($ingredient['note']) : null;
				$ingredientGroup = isset($ingredient['ingredient_group']) && !empty(trim($ingredient['ingredient_group'])) ? trim($ingredient['ingredient_group']) : null;

				$ingredientLog[] = "Name: " . $ingredientName;
				$ingredientLog[] = "Menge: " . $ingredientAmount;
				$ingredientLog[] = "Einheit: " . $ingredientUnitName;
				$ingredientLog[] = "Notiz: " . ($ingredientNote ?? 'Keine');
				$ingredientLog[] = "Gruppe: " . ($ingredientGroup ?? 'Keine');

				$unit = $this->_findOrCreateQuantityUnit($ingredientUnitName, $apiKey, $ingredientLog);
				$quantityUnitId = (int)$unit->id;

				$product = $this->_findOrCreateProduct($ingredientName, $quantityUnitId, $ingredientLog);
				$productId = (int)$product->id;

				$stockUnitId = $this->_ensureProductStockUnit($product, $quantityUnitId, $ingredientLog);

				if ($stockUnitId !== $quantityUnitId) {
					$this->_ensureUnitConversion($productId, $quantityUnitId, $stockUnitId, $ingredientLog);
				}

				$amountInStockUnit = $this->_calculateAmountInStockUnit($productId, $ingredientAmount, $quantityUnitId, $stockUnitId, $ingredientUnitName, $ingredientLog);

				$this->_insertRecipePosition([
					'recipe_id' => $recipeId,
					'product_id' => $productId,
					'amount' => $amountInStockUnit,
					'qu_id' => $stockUnitId,
					'note' => $ingredientNote,
					'ingredient_group' => $ingredientGroup,
				], $ingredientLog);

				$ingredientLog[] = "Rezeptposition erfolgreich erstellt!";

			} catch (\Exception $ex) {
				$ingredientLog[] = "FEHLER bei Verarbeitung von Zutat '" . ($ingredientName ?? json_encode($ingredient)) . "': " . $ex->getMessage();
				$ingredientLog[] = "Stack Trace: " . $ex->getTraceAsString();
			} finally {
				$this->_logMessage(implode("\n", $ingredientLog), $logMessages);
			}
		}
	}

	private function _findOrCreateQuantityUnit(string $unitName, string $apiKey, array &$ingredientLog): object
	{
		$unit = $this->getDatabase()->quantity_units()->where('name = ?', $unitName)->fetch();

		if ($unit === null || $unit === false)
		{
			$ingredientLog[] = "Einheit '" . $unitName . "' nicht gefunden. Frage Gemini nach Singular/Plural.";

			list($singular, $plural) = $this->_getUnitFormsFromGemini($unitName, $apiKey, $ingredientLog);

			$unitData = [
				'name' => $singular,
				'name_plural' => $plural,
				'description' => '',
				'active' => 1
			];
			$ingredientLog[] = "Erstelle neue Einheit: " . json_encode($unitData);

			$newUnit = $this->getDatabase()->quantity_units()->createRow($unitData);
			$newUnit->save();
			$quantityUnitId = $newUnit->id;
			if (empty($quantityUnitId)) {
				throw new \Exception("Konnte neue Einheit '$unitName' nicht speichern.");
			}
			$ingredientLog[] = "Neue Einheit-ID: " . $quantityUnitId;
			return $newUnit;
		}
		else
		{
			$ingredientLog[] = "Bestehende Einheit gefunden mit ID: " . $unit->id;
			return $unit;
		}
	}

	private function _getUnitFormsFromGemini(string $unitName, string $apiKey, array &$log): array
	{
		$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
		$log[] = "API-URL für Einheiten-Lookup: " . $apiUrl;

		$prompt = <<<PROMPT
		Gib den Singular und Plural für die folgende Maßeinheit zurück: "$unitName".
		Antworte ausschließlich als JSON-Objekt ohne umschließende Markdown-Formatierung.
		Das JSON muss exakt die Struktur {"singular": "...", "plural": "..."} haben.
		Wenn Singular und Plural identisch sind (z.B. bei 'Stück', 'g', 'ml'), gib denselben Wert für beide an.
		Gib die Namen möglichst auf Deutsch zurück. Gib für den Singular Fall immer die Maßeinheit in der Form an, 
		wie sie von mir übergeben wurde.

		Beispiele:
		Einheit: Tasse -> {"singular": "Tasse", "plural": "Tassen"}
		Einheit: g -> {"singular": "g", "plural": "g"}
		Einheit: EL -> {"singular": "EL", "plural": "EL"}
		Einheit: Prise -> {"singular": "Prise", "plural": "Prisen"}
		Einheit: Stück -> {"singular": "Stück", "plural": "Stücke"}

		Maßeinheit: $unitName
		PROMPT;

		$payload = [
			'contents' => [['parts' => [['text' => $prompt]]]],
			'generationConfig' => [
				'response_mime_type' => 'application/json',
				'temperature' => 0.1,
				'maxOutputTokens' => 100
			]
		];

		$log[] = "Payload für Einheiten-API vorbereitet für: " . $unitName;
		list($httpCode, $responseData, $curlError) = $this->_executeCurlRequest($apiUrl, $payload, $log);

		$defaultSingular = $unitName;
		$lowerUnitName = strtolower($unitName);
		$defaultPlural = (in_array($lowerUnitName, ['g', 'kg', 'ml', 'l', 'stück', 'el', 'tl'])) ? $unitName : $unitName . 's';

		if ($httpCode !== 200 || $responseData === false) {
			$log[] = "WARNUNG: Fehler beim Abrufen der Einheitenformen von Gemini (HTTP {$httpCode} - {$curlError}). Verwende Standard: Singular='{$defaultSingular}', Plural='{$defaultPlural}'";
			return [$defaultSingular, $defaultPlural];
		}

		try {
			$responseJson = json_decode($responseData, true);

			if (isset($responseJson['error'])) {
				throw new \Exception('Gemini API Fehler (Einheit): ' . $responseJson['error']['message']);
			}
			if (!isset($responseJson['candidates'][0]['content']['parts'][0]['text'])) {
				if (isset($responseJson['promptFeedback']['blockReason'])) {
					throw new \Exception('Ungültige Antwort von Gemini API (Einheit): Anfrage blockiert. Grund: ' . $responseJson['promptFeedback']['blockReason']);
				}
				throw new \Exception('Ungültige oder unerwartete Antwortstruktur von Gemini API (Einheit)');
			}

			$unitJsonText = $responseJson['candidates'][0]['content']['parts'][0]['text'];
			$log[] = "Extrahierter JSON-Text (Einheit): " . $unitJsonText;

			$unitForms = json_decode($unitJsonText, true);
			if (json_last_error() === JSON_ERROR_NONE && isset($unitForms['singular']) && isset($unitForms['plural'])) {
				$log[] = "Einheitenformen erfolgreich von Gemini erhalten: Singular='{$unitForms['singular']}', Plural='{$unitForms['plural']}'";
				return [trim($unitForms['singular']), trim($unitForms['plural'])];
			} else {
				$cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', $unitJsonText);
				$unitForms = json_decode($cleanedJson, true);
				if (json_last_error() === JSON_ERROR_NONE && isset($unitForms['singular']) && isset($unitForms['plural'])) {
					$log[] = "Einheitenformen erfolgreich von Gemini erhalten (nach Bereinigung): Singular='{$unitForms['singular']}', Plural='{$unitForms['plural']}'";
					return [trim($unitForms['singular']), trim($unitForms['plural'])];
				}
				throw new \Exception('Konnte Einheiten-JSON nicht parsen: ' . json_last_error_msg() . ' - Text: ' . $unitJsonText);
			}
		} catch (\Exception $e) {
			$log[] = "WARNUNG: Fehler beim Verarbeiten der Gemini-Antwort für Einheiten ({$e->getMessage()}). Verwende Standard: Singular='{$defaultSingular}', Plural='{$defaultPlural}'";
			return [$defaultSingular, $defaultPlural];
		}
	}

	private function _findOrCreateProduct(string $productName, int $quantityUnitId, array &$ingredientLog): object
	{
		$product = $this->getDatabase()->products()->where('name = ?', $productName)->fetch();

		if ($product === null || $product === false)
		{
			$ingredientLog[] = "Produkt '" . $productName . "' nicht gefunden. Erstelle neues Produkt.";
			$productData = [
				'name' => $productName,
				'description' => '',
				'location_id' => $this->getDefaultLocationId(),
				'qu_id_purchase' => $quantityUnitId,
				'qu_id_stock' => $quantityUnitId,
				'min_stock_amount' => 0,
				'default_best_before_days' => 0,
				'active' => 1
			];
			$ingredientLog[] = "Erstelle neues Produkt: '" . $productName . "' mit Einheit-ID " . $quantityUnitId;

			$newProduct = $this->getDatabase()->products()->createRow($productData);
			$newProduct->save();
			$productId = $newProduct->id;
			if (empty($productId)) {
				throw new \Exception("Konnte neues Produkt '$productName' nicht speichern.");
			}
			$ingredientLog[] = "Neue Produkt-ID: " . $productId;
			return $newProduct;
		}
		else
		{
			$ingredientLog[] = "Bestehendes Produkt gefunden mit ID: " . $product->id;
			return $product;
		}
	}

	private function _ensureProductStockUnit(object $product, int $ingredientUnitId, array &$ingredientLog): int
	{
		$stockUnitId = $product->qu_id_stock ? (int)$product->qu_id_stock : null;
		$productId = (int)$product->id;

		if ($stockUnitId === null || $stockUnitId === 0) {
			$ingredientLog[] = "Produkt (ID: {$productId}) hat keine Lagereinheit (qu_id_stock). Setze sie auf: {$ingredientUnitId} (Einheit dieser Zutat)";
			$this->getDatabase()->products()->where('id = ?', $productId)->update([
				'qu_id_stock' => $ingredientUnitId
			]);
			return $ingredientUnitId;
		} else {
			$ingredientLog[] = "Produkt (ID: {$productId}) hat Lagereinheit (qu_id_stock): {$stockUnitId}";
			return $stockUnitId;
		}
	}

	private function _ensureUnitConversion(int $productId, int $fromQuId, int $toQuId, array &$ingredientLog): void
	{
		$ingredientLog[] = "Zutateneinheit (ID: {$fromQuId}) unterscheidet sich von Lagereinheit (ID: {$toQuId}). Prüfe Konvertierung.";

		// Use raw SQL fragment for the OR condition to avoid Closure issues with LessQL chaining
		$conversionExists = $this->getDatabase()->quantity_unit_conversions()
			->where('product_id = ?', $productId)
			->where('( (from_qu_id = ? AND to_qu_id = ?) OR (from_qu_id = ? AND to_qu_id = ?) )', $fromQuId, $toQuId, $toQuId, $fromQuId)
			->fetch();

		if ($conversionExists === null || $conversionExists === false) {
			$conversionData = [
				'product_id' => $productId,
				'from_qu_id' => $fromQuId,
				'to_qu_id' => $toQuId,
				'factor' => 1.0,
			];
			$ingredientLog[] = "Erstelle Standard-Konvertierung (Faktor 1.0) von Einheit {$fromQuId} zu {$toQuId} für Produkt {$productId}: " . json_encode($conversionData);
			$this->getDatabase()->quantity_unit_conversions()->createRow($conversionData)->save();
		} else {
			$ingredientLog[] = "Konvertierung zwischen Einheit {$fromQuId} und {$toQuId} existiert bereits.";
		}
	}

	private function _calculateAmountInStockUnit(int $productId, float $ingredientAmount, int $fromQuId, int $toQuId, string $ingredientUnitName, array &$ingredientLog): float
	{
		if ($fromQuId === $toQuId) {
			return $ingredientAmount;
		}

		$conversionFactor = $this->getQuantityUnitConversionFactor($productId, $fromQuId, $toQuId);

		if ($conversionFactor !== null) {
			$amountInStockUnit = $ingredientAmount * $conversionFactor;
			$ingredientLog[] = "Menge {$ingredientAmount} {$ingredientUnitName} (ID: {$fromQuId}) umgerechnet zu {$amountInStockUnit} in Lagereinheit (ID: {$toQuId}) mit Faktor {$conversionFactor}.";
			return $amountInStockUnit;
		} else {
			$ingredientLog[] = "WARNUNG: Konnte keinen Konvertierungsfaktor von Einheit {$fromQuId} zu {$toQuId} für Produkt {$productId} finden (trotz Erstellungsversuch). Verwende Menge {$ingredientAmount} unverändert.";
			return $ingredientAmount;
		}
	}

	private function _insertRecipePosition(array $posData, array &$ingredientLog): void
	{
		// Ensure keys exist, even if null, matching expected DB columns
		$posData = array_merge([
			'note' => null,
			'ingredient_group' => null,
		], $posData);

		$ingredientLog[] = "Erstelle Rezeptposition (recipes_pos): " . json_encode($posData);
		$this->getDatabase()->recipes_pos()->createRow($posData)->save();
	}

	private function _downloadAndAssignPicture(int $recipeId, string $pictureUrl, array &$logMessages): void
	{
		$this->_logMessage("Versuche, Bild herunterzuladen von: " . $pictureUrl, $logMessages);
		$pictureDir = defined('GROCY_DATAPATH') ? GROCY_DATAPATH . '/storage/recipepictures' : './storage/recipepictures';
		if (!file_exists($pictureDir)) {
			if (!mkdir($pictureDir, 0777, true) && !is_dir($pictureDir)) {
				$this->_logMessage("Warnung: Konnte Bildverzeichnis nicht erstellen: " . $pictureDir, $logMessages);
			} else {
				$this->_logMessage("Bildverzeichnis erstellt: " . $pictureDir, $logMessages);
			}
		}

		$pathInfo = pathinfo(parse_url($pictureUrl, PHP_URL_PATH));
		$extension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : 'jpg';
		if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
			$extension = 'jpg';
		}
		$pictureFileName = 'recipe_' . $recipeId . '_' . time() . '.' . $extension;
		$fullPath = $pictureDir . '/' . $pictureFileName;

		$imgCurl = curl_init($pictureUrl);
		curl_setopt_array($imgCurl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => 'Grocy Recipe Importer/1.0'
		]);
		$imageData = curl_exec($imgCurl);
		$imgHttpCode = curl_getinfo($imgCurl, CURLINFO_HTTP_CODE);
		$imgCurlError = curl_error($imgCurl);
		curl_close($imgCurl);

		if ($imgHttpCode === 200 && $imageData !== false && !empty($imageData))
		{
			if (file_put_contents($fullPath, $imageData) !== false) {
				$this->_logMessage("Bild erfolgreich heruntergeladen und gespeichert unter: " . $fullPath, $logMessages);
				$this->getDatabase()->recipes()->where('id = ?', $recipeId)->update(['picture_file_name' => $pictureFileName]);
				$this->_logMessage("Rezept-Datensatz aktualisiert mit Bild-Dateiname: " . $pictureFileName, $logMessages);
			} else {
				 $this->_logMessage("FEHLER: Bild konnte nicht in Datei gespeichert werden: " . $fullPath, $logMessages);
			}
		} else {
			$this->_logMessage("Bild konnte nicht heruntergeladen werden. HTTP-Code: {$imgHttpCode}, cURL-Fehler: {$imgCurlError}", $logMessages);
		}
	}

	private function _handleImportError(\Exception $ex, ?string $logFilePath, array &$logMessages, Response $response): Response
	{
		$errorMessage = $ex->getMessage();
		$errorTrace = $ex->getTraceAsString();
		$this->_logMessage("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!", $logMessages);
		$this->_logMessage("!!! KRITISCHER FEHLER AUFGETRETEN !!!", $logMessages);
		$this->_logMessage("Fehlermeldung: " . $errorMessage, $logMessages);
		$this->_logMessage("Stack Trace:\n" . $errorTrace, $logMessages);
		$this->_logMessage("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!", $logMessages);

		if ($logFilePath === null) {
			$logFileDir = defined('GROCY_DATAPATH') ? GROCY_DATAPATH . '/logs' : './logs';
			if (!file_exists($logFileDir)) { @mkdir($logFileDir, 0777, true); }
			$logFilePath = $logFileDir . '/recipe_import_error_' . time() . '.log';
		}
		@file_put_contents($logFilePath, implode("\n", $logMessages));

		return $this->GenericErrorResponse($response, $errorMessage . " (Details siehe Log: " . ($logFilePath ?? 'konnte nicht geschrieben werden') . ")", 500);
	}

	private function _executeCurlRequest(string $url, array $payload, array &$logMessages): array
	{
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload),
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_TIMEOUT => 60,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => 'Grocy Gemini Client/1.0'
		]);

		$responseData = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$curlError = curl_error($curl);
		curl_close($curl);

		$this->_logMessage("API-Antwort erhalten: HTTP-Code " . $httpCode . " von " . $url, $logMessages);
		if (!empty($curlError)) {
			$this->_logMessage("cURL Fehler: " . $curlError, $logMessages);
		}
		$this->_logMessage("API-Antwort Rohdaten (gekürzt): " . substr((string)$responseData, 0, 500) . ((strlen((string)$responseData) > 500) ? '...' : ''), $logMessages);

		return [$httpCode, $responseData, $curlError];
	}

	private function getDefaultLocationId() {
//		$defaultLocation = $this->getDatabase()->locations()->where('is_default = ?', 1)->fetch();
		return 0;
	}

	private function getQuantityUnitConversionFactor(int $productId, int $fromQuId, int $toQuId): ?float
	{
		if ($fromQuId === $toQuId) {
			return 1.0;
		}

		$conversion = $this->getDatabase()->quantity_unit_conversions()
			->where('product_id = ?', $productId)
			->where('from_qu_id = ?', $fromQuId)
			->where('to_qu_id = ?', $toQuId)
			->fetch();
		if ($conversion && $conversion->factor) {
			return floatval($conversion->factor);
		}

		$reverseConversion = $this->getDatabase()->quantity_unit_conversions()
			->where('product_id = ?', $productId)
			->where('from_qu_id = ?', $toQuId)
			->where('to_qu_id = ?', $fromQuId)
			->fetch();
		if ($reverseConversion && $reverseConversion->factor != 0) {
			return 1.0 / floatval($reverseConversion->factor);
		}

		$genericConversion = $this->getDatabase()->quantity_unit_conversions()
			->where('product_id IS NULL')
			->where('from_qu_id = ?', $fromQuId)
			->where('to_qu_id = ?', $toQuId)
			->fetch();
		if ($genericConversion && $genericConversion->factor) {
			 return floatval($genericConversion->factor);
		}

		$genericReverseConversion = $this->getDatabase()->quantity_unit_conversions()
			 ->where('product_id IS NULL')
			 ->where('from_qu_id = ?', $toQuId)
			 ->where('to_qu_id = ?', $fromQuId)
			 ->fetch();
		 if ($genericReverseConversion && $genericReverseConversion->factor != 0) {
			  return 1.0 / floatval($genericReverseConversion->factor);
		 }

		return null;
	}
}
