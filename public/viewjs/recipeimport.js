$("#import-recipe-button").on("click", function(e) {
    $("#import-recipe-modal").modal("show");
    $("#html-content").val("");
    $("#recipe-import-result").addClass("d-none");
    $("#import-recipe-form-loading").addClass("d-none");
    $("#import-recipe-form").removeClass("d-none");
    $("#api-key-row").toggleClass("d-none", Grocy.UserSettings.recipe_import_gemini_api_key !== "");
});

$("#import-recipe-submit").on("click", function(e) {
    e.preventDefault();
    
    // Validierung
    var htmlContent = $("#html-content").val();
    if (htmlContent.trim() === "") {
        Grocy.FrontendHelpers.ShowGenericError("HTML-Inhalt ist erforderlich");
        return;
    }

    // API-Key aus Einstellungen oder Eingabefeld
    var apiKey = Grocy.UserSettings.recipe_import_gemini_api_key || $("#gemini-api-key").val();
    if (apiKey.trim() === "") {
        Grocy.FrontendHelpers.ShowGenericError("Gemini API-Schlüssel ist erforderlich");
        return;
    }

    // UI-Status aktualisieren
    $("#import-recipe-form").addClass("d-none");
    $("#import-recipe-form-loading").removeClass("d-none");
    
    // API-Anfrage
    var jsonData = {
        html: htmlContent,
        api_key: apiKey
    };
    
    Grocy.Api.Post('recipes/import-from-html', jsonData,
        function(result) {
            // Erfolg: Zur neuen Rezeptseite weiterleiten
            toastr.success(__t("Recipe successfully imported"));
            window.location.href = U('/recipe/' + result.created_recipe_id);
        },
        function(xhr) {
            // Fehler anzeigen und Formular wieder einblenden
            $("#import-recipe-form").removeClass("d-none");
            $("#import-recipe-form-loading").addClass("d-none");
            Grocy.FrontendHelpers.ShowGenericError('Error while importing recipe', xhr.response);
        }
    );
});

// API-Key in Benutzereinstellungen speichern
$("#save-api-key").on("click", function(e) {
    e.preventDefault();
    
    var apiKey = $("#gemini-api-key").val();
    if (apiKey.trim() === "") {
        Grocy.FrontendHelpers.ShowGenericError("API-Schlüssel darf nicht leer sein");
        return;
    }
    
    Grocy.FrontendHelpers.SaveUserSetting("recipe_import_gemini_api_key", apiKey, 
        function() {
            toastr.success(__t("API key saved"));
            Grocy.UserSettings.recipe_import_gemini_api_key = apiKey;
            $("#api-key-row").addClass("d-none");
        },
        function(xhr) {
            Grocy.FrontendHelpers.ShowGenericError('Error while saving API key', xhr.response);
        }
    );
});