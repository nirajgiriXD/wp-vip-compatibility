jQuery(document).ready(function ($) {
  const ajaxRequests = [];
  const table = $(".wvc-table");
  const tabs = $("#wvc-filter-tabs button");
  const tableData = $("td.vip-compatibility-status");
  const logNoteContainer = $("#wvc-log-note-container");
  const logNoteFilename = logNoteContainer?.data("filename");
  const targetEntity = table.data("target-entity");

  // These are the directories that undergoes async compatibility check
  const asyncCompatibilityCheckFiles = ["plugins", "themes", "mu-plugins"];

  // Disable tabs initially
  if (
    tableData.length > 0 &&
    asyncCompatibilityCheckFiles.includes(targetEntity)
  ) {
    tabs.prop("disabled", true);
  }

  // Check compatibility for each directory
  tableData.each(function () {
    const statusCell = $(this);
    const directoryPath = statusCell.data("directory-path");

    // Send AJAX request
    const request = $.ajax({
      url: _WPVC_.ajax_url,
      type: "POST",
      data: {
        _ajax_nonce: _WPVC_.nonce,
        action: "wvc_check_vip_compatibility",
        directory_path: directoryPath,
      },
      beforeSend: function () {
        statusCell.text(_WPVC_.i18n.checking);
      },
      success: function (response) {
        if (response.success) {
          statusCell
            .text(response.data.message)
            .addClass(response.data.class);
        } else {
          statusCell
            .text(_WPVC_.i18n.error)
            .addClass("not-compatible");
        }
      },
      error: function () {
        statusCell.text(_WPVC_.i18n.error).addClass("not-compatible");
      },
    });

    // Store the request
    ajaxRequests.push(request);
  });

  // Once all AJAX requests are done, enable filter tabs and load the log note
  $.when.apply($, ajaxRequests).done(function () {
    tabs.prop("disabled", false);

    $.ajax({
      url: _WPVC_.ajax_url,
      type: "POST",
      data: {
        _ajax_nonce: _WPVC_.nonce,
        action: "wvc_render_log_note",
        filename: logNoteFilename,
      },
      success: function (response) {
        if (response.success) {
          logNoteContainer.html(response.data.message);
        } else {
          logNoteContainer.html(response.data.message);
        }
      },
      error: function () {
        logNoteContainer.html(
          "<p><strong>" +
            _WPVC_.i18n.error +
            ":</strong> " +
            _WPVC_.i18n.unableToFetchLogDetails +
            "</p>"
        );
      },
    });
  });

  // Tabs filter functionality
  $("#wvc-filter-tabs button").on("click", function () {
    const filter = $(this).data("filter");
    $("#wvc-filter-tabs button").removeClass("active");
    $(this).addClass("active");

    if (filter === "all") {
      table.find("tbody tr").show();
    } else if (filter === "compatible") {
      table.find("tbody tr").hide();
      table.find("td.compatible").closest("tr").show();
    } else if (filter === "incompatible") {
      table.find("tbody tr").hide();
      table.find("td.not-compatible").closest("tr").show();
    }
  });

  // Tabs navigation functionality
  const navigationTabs = $("#wvc-navigation-tabs button");
  const contents = $(".wvc-navigation-tab-content");

  navigationTabs.on("click", function () {
    navigationTabs.removeClass("active");
    contents.removeClass("active");

    $(this).addClass("active");
    $("#" + $(this).data("tab")).addClass("active");
  });

  // Chart functionality
  const chartInstances = {};
  const chartContainer = $("#wvc-chart-container");
  const categories = chartContainer.data("categories");

  // Update the chart with the new data
  const updateChart = (category, data) => {
    const canvasId = "chart-" + category;
    const ctx = document.getElementById(canvasId).getContext("2d");

    // Destroy the existing chart instance if it exists
    if (chartInstances[canvasId]) {
      chartInstances[canvasId].destroy();
    }

    // Create a new chart and store it
    chartInstances[canvasId] = new Chart(ctx, {
      type: "pie",
      data: {
        labels: ["Compatible", "Not-Compatible"],
        datasets: [
          {
            data: [data.compatible, data.not_compatible],
            backgroundColor: ["#4CAF50", "#FF5733"],
            hoverOffset: 4,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: "bottom",
          },
          title: {
            display: true,
            text: category.toUpperCase(),
            font: {
              size: 12,
            },
            padding: 10,
          },
        },
      },
    });
  };

  // Fetch data for each category and update the chart
  categories.forEach((category) => {
    $.ajax({
      url: _WPVC_.ajax_url,
      type: "POST",
      data: {
        _ajax_nonce: _WPVC_.nonce,
        action: "wvc_get_chart_data",
        category: category,
      },
      beforeSend: function () {
        updateChart(category, {
          compatible: 0,
          not_compatible: 0,
        });
      },
      success: function (response) {
        if (response.success) {
          updateChart(category, response.data);
        } else {
          alert(_WPVC_.i18n.unableToFetchData);
        }
      },
      error: function () {
        alert(_WPVC_.i18n.unableToFetchData);
      },
    });
  });
});
