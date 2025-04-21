import moment from 'moment';

export {}; // Make this file a module

declare global {
    interface Window {
        $: JQueryStatic;
        moment: typeof moment;
        BoolVal(value: any): boolean;
        Grocy: {
            UserId: number;
            UserSettings: {
                show_clock_in_header: any; // Use specific type if known
            };
            HeaderClockInterval: number | null; // Timer ID is a number in browsers
        };
    }
}

// Ensure Grocy object exists
if (!(window as any).Grocy) {
    (window as any).Grocy = {};
}
window.Grocy.HeaderClockInterval = window.Grocy.HeaderClockInterval || null;

function RefreshHeaderClock(): void
{
	window.$("#clock-small").text(window.moment().format("l LT"));
	window.$("#clock-big").text(window.moment().format("LLLL"));
}

function CheckHeaderClockEnabled(): void
{
	if (window.Grocy.UserId === -1)
	{
		return;
	}

	// Refresh the clock in the header every second when enabled
	if (window.BoolVal(window.Grocy.UserSettings.show_clock_in_header))
	{
		RefreshHeaderClock();
		window.$("#clock-container").removeClass("d-none");

        // Clear existing interval before setting a new one
        if (window.Grocy.HeaderClockInterval !== null) {
            clearInterval(window.Grocy.HeaderClockInterval);
        }

		window.Grocy.HeaderClockInterval = window.setInterval(function()
		{
			RefreshHeaderClock();
		}, 1000);
	}
	else
	{
		if (window.Grocy.HeaderClockInterval !== null)
		{
			clearInterval(window.Grocy.HeaderClockInterval);
			window.Grocy.HeaderClockInterval = null;
		}

		window.$("#clock-container").addClass("d-none");
	}
}

// Initial check
CheckHeaderClockEnabled();

// Set checkbox state based on user setting
if (window.Grocy.UserId !== -1 && window.BoolVal(window.Grocy.UserSettings.show_clock_in_header))
{
	window.$("#show-clock-in-header").prop("checked", true);
}

// Event listener for the checkbox
window.$(document).on("change", "#show-clock-in-header", function()
{
	CheckHeaderClockEnabled();
});
