(function () {
  "use strict";

  function pad(n) {
    return String(n).padStart(2, "0");
  }

  function formatUtc(d) {
    return (
      d.getUTCFullYear() +
      "-" + pad(d.getUTCMonth() + 1) +
      "-" + pad(d.getUTCDate()) +
      " " + pad(d.getUTCHours()) +
      ":" + pad(d.getUTCMinutes()) +
      ":" + pad(d.getUTCSeconds()) +
      "Z"
    );
  }

  function formatLocal(d) {
    return (
      d.getFullYear() +
      "-" + pad(d.getMonth() + 1) +
      "-" + pad(d.getDate()) +
      " " + pad(d.getHours()) +
      ":" + pad(d.getMinutes()) +
      ":" + pad(d.getSeconds())
    );
  }

  // ISO week number (based on local date, no timezone string)
  function isoWeekNumberLocal(date) {
    // Copy as local date (midday avoids DST edge weirdness)
    var d = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12, 0, 0);

    // ISO: week starts Monday, week 1 contains Jan 4th
    var day = d.getDay(); // 0=Sun..6=Sat
    var isoDay = day === 0 ? 7 : day; // 1=Mon..7=Sun

    // Move to Thursday of this week
    d.setDate(d.getDate() + (4 - isoDay));

    // Week-year is the year of that Thursday (usually same as local year)
    var weekYear = d.getFullYear();

    // Jan 1 of weekYear
    var yearStart = new Date(weekYear, 0, 1, 12, 0, 0);

    // Calculate week number
    var diffDays = Math.floor((d - yearStart) / 86400000);
    var week = Math.floor(diffDays / 7) + 1;

    return { week: week, year: weekYear };
  }

  function dayNameLocal(date) {
    var names = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    return names[date.getDay()] || "Today";
  }

  function tick() {
    var now = new Date();

    var utcEl = document.getElementById("tzbar-utc");
    if (utcEl) {
      utcEl.textContent = formatUtc(now);
    }

    var localEl = document.getElementById("tzbar-local");
    if (localEl) {
      localEl.textContent = formatLocal(now);
    }

    // Requirement #1: always show plain "Local"
    var labelEl = document.getElementById("tzbar-local-label");
    if (labelEl) {
      labelEl.textContent = "Local";
    }

    // Requirement #2: show current local week/day line
    var metaEl = document.getElementById("tzbar-meta");
    if (metaEl) {
      var dn = dayNameLocal(now);
      var wk = isoWeekNumberLocal(now);
      metaEl.textContent = "Today is " + dn + ", in week " + wk.week + " of " + wk.year;
    }
  }

  tick();
  window.setInterval(tick, 1000);
})();

