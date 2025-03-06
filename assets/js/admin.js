jQuery(document).ready(function ($) {
  const ajaxRequests = [];
  $("td.vip-compatibility-status").each(function () {
    const $statusCell = $(this);
    const directoryPath = $statusCell.data("directory-path");

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
        $statusCell.text(_WPVC_.i18n.checking);
      },
      success: function (response) {
        if (response.success) {
          $statusCell
            .text(response.data.message)
            .addClass(response.data.class);
        } else {
          $statusCell.text(_WPVC_.i18n.error).addClass("not-compatible");
        }
      },
      error: function () {
        $statusCell.text(_WPVC_.i18n.error).addClass("not-compatible");
      },
    });

    // Store the request
    ajaxRequests.push(request);
  });

  // Once all AJAX requests are done, load the log note
  $.when.apply($, ajaxRequests).done(function () {
    const container = $("#wvc-log-note-container");
    const filename = container.data("filename");

    $.ajax({
        url: _WPVC_.ajax_url,
        type: "POST",
        data: {
            _ajax_nonce: _WPVC_.nonce,
            action: "wvc_render_log_note",
            filename: filename
        },
        success: function (response) {
            if (response.success) {
              console.log(response.data.message);
                container.html(response.data.message);
            } else {
                container.html(response.data.message);
            }
        },
        error: function () {
            container.html('<p><strong>' + _WPVC_.i18n.error + ':</strong> ' + _WPVC_.i18n.unableToFetchLogDetails + '</p>');
        }
    });
  });
});
