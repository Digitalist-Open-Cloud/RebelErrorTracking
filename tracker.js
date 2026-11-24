(function () {
  if (window.rebelErrorTrackingLoaded || !window.addEventListener) {
      return;
  }
  window.rebelErrorTrackingLoaded = true;

  // A page that throws in a loop must not flood the tracker.
  var MAX_ERRORS_PER_PAGE_LOAD = 10;
  var MAX_MESSAGE = 500;
  var MAX_SOURCE = 500;
  var MAX_STACK = 2000;

  var EXTENSION = /(chrome|moz|safari|safari-web)-extension:\/\//;
  var NOISE = /ResizeObserver loop/;

  var seen = {};
  var sent = 0;

  function cut(value, max) {
      return String(value === null || value === undefined ? '' : value).substring(0, max);
  }

  function report(type, message, source, line, column, stack) {
      message = cut(message, MAX_MESSAGE);
      source = cut(String(source || '').split(/[?#]/)[0], MAX_SOURCE);
      stack = cut(stack, MAX_STACK);

      if (!message || sent >= MAX_ERRORS_PER_PAGE_LOAD || EXTENSION.test(source + stack) || NOISE.test(message)) {
          return;
      }

      var key = [type, message, source, line, column].join('|');
      if (seen[key]) {
          return;
      }
      seen[key] = true;
      sent++;

      var request = 'ca=1&ret_t=' + type
          + '&ret_m=' + encodeURIComponent(message)
          + '&ret_s=' + encodeURIComponent(source)
          + '&ret_l=' + (parseInt(line, 10) || 0)
          + '&ret_c=' + (parseInt(column, 10) || 0)
          + '&ret_k=' + encodeURIComponent(stack);

      var trackers = window.Matomo.getAsyncTrackers();
      for (var i = 0; i < trackers.length; i++) {
          if (trackers[i].getSiteId() && trackers[i].getTrackerUrl()) {
              trackers[i].trackRequest(request);
          }
      }
  }

  // An error thrown by a listener for errors would be reported to that listener again.
  function safely(listener) {
      return function (event) {
          try {
              listener(event);
          } catch (e) {
              // nothing to do
          }
      };
  }

  // In the capture phase, to also see the elements that failed to load: those events do not bubble.
  window.addEventListener('error', safely(function (event) {
      var target = event.target;
      if (target && target !== window && target.tagName) {
          var address = target.src || target.href;
          if (address) {
              report('resource', 'Failed to load ' + target.tagName.toLowerCase(), address, 0, 0, '');
          }
          return;
      }
      report('js', event.message, event.filename, event.lineno, event.colno, event.error && event.error.stack);
  }), true);

  window.addEventListener('unhandledrejection', safely(function (event) {
      var reason = event.reason;
      var message = reason && reason.message ? (reason.name ? reason.name + ': ' : '') + reason.message : reason;
      report('promise', message, '', 0, 0, reason && reason.stack);
  }));
})();
