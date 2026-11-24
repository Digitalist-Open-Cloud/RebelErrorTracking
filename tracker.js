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

  // How long an error waits for a tracker that knows its website.
  var RETRY_MS = 500;
  var MAX_RETRIES = 20;

  var EXTENSION = /(chrome|moz|safari|safari-web)-extension:\/\//;
  var NOISE = /ResizeObserver loop/;

  var seen = {};
  var sent = 0;
  var waiting = [];
  var retries = 0;

  function cut(value, max) {
      return String(value === null || value === undefined ? '' : value).substring(0, max);
  }

  function readyTrackers() {
      var ready = [];
      var trackers = window.Matomo && window.Matomo.getAsyncTrackers ? window.Matomo.getAsyncTrackers() : [];
      for (var i = 0; i < trackers.length; i++) {
          if (trackers[i].getSiteId() && trackers[i].getTrackerUrl()) {
              ready.push(trackers[i]);
          }
      }
      return ready;
  }

  // The tracker of a page can be set up after its first errors, by a tag manager for one.
  function flush() {
      var trackers = readyTrackers();
      if (!trackers.length) {
          if (retries++ < MAX_RETRIES) {
              setTimeout(flush, RETRY_MS);
          } else {
              waiting = [];
          }
          return;
      }
      while (waiting.length) {
          var request = waiting.shift();
          for (var i = 0; i < trackers.length; i++) {
              trackers[i].trackRequest(request);
          }
      }
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

      waiting.push('ca=1&ret_t=' + type
          + '&ret_m=' + encodeURIComponent(message)
          + '&ret_s=' + encodeURIComponent(source)
          + '&ret_l=' + (parseInt(line, 10) || 0)
          + '&ret_c=' + (parseInt(column, 10) || 0)
          + '&ret_k=' + encodeURIComponent(stack));
      if (waiting.length === 1) {
          flush();
      }
  }

  function onError(event) {
      var target = event.target;
      if (target && target !== window && target.tagName) {
          var address = target.src || target.href;
          if (address) {
              report('resource', 'Failed to load ' + target.tagName.toLowerCase(), address, 0, 0, '');
          }
          return;
      }
      report('js', event.message, event.filename, event.lineno, event.colno, event.error && event.error.stack);
  }

  function onRejection(event) {
      var reason = event.reason;
      var message = reason && reason.message ? (reason.name ? reason.name + ': ' : '') + reason.message : reason;
      report('promise', message, '', 0, 0, reason && reason.stack);
  }

  function onPolicyViolation(event) {
      var message = (event.disposition === 'report' ? 'Would be blocked by ' : 'Blocked by ')
          + (event.effectiveDirective || event.violatedDirective);
      var stack = event.sourceFile ? 'at ' + event.sourceFile + ':' + event.lineNumber + ':' + event.columnNumber : '';
      report('csp', message, event.blockedURI, event.lineNumber, event.columnNumber, stack);
  }

  function onBrowserReports(reports) {
      for (var i = 0; i < reports.length; i++) {
          var body = reports[i].body || {};
          report('browser', reports[i].type + ': ' + body.message, body.sourceFile, body.lineNumber, body.columnNumber, '');
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

  // The errors a snippet in the page collected before this script was loaded.
  var early = window.rebelErrorQueue;
  window.rebelErrorQueue = { push: function () {} };
  if (early && early.length) {
      var listeners = { unhandledrejection: onRejection, securitypolicyviolation: onPolicyViolation };
      for (var i = 0; i < early.length; i++) {
          safely(listeners[early[i] && early[i].type] || onError)(early[i]);
      }
  }

  // In the capture phase, to also see the elements that failed to load: those events do not bubble.
  window.addEventListener('error', safely(onError), true);
  window.addEventListener('unhandledrejection', safely(onRejection));
  window.addEventListener('securitypolicyviolation', safely(onPolicyViolation));

  if (window.ReportingObserver) {
      safely(function () {
          new window.ReportingObserver(safely(onBrowserReports), { types: ['deprecation', 'intervention'], buffered: true }).observe();
      })();
  }
})();
