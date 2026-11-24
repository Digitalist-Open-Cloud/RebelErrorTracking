import { assertEquals } from "jsr:@std/assert@1";

// Runs tracker.js in a faked browser. Returns what to fire events with and the requests sent.
function load({ siteId = 1, loadedBefore = false, queue, observer = false } = {}) {
  const listeners = {};
  const requests = [];
  const timers = [];
  const env = { siteId, requests };
  const tracker = {
    getSiteId: () => env.siteId,
    getTrackerUrl: () => "https://m.example/matomo.php",
    trackRequest: (request) => requests.push(new URLSearchParams(request)),
  };
  const window = {
    rebelErrorTrackingLoaded: loadedBefore,
    rebelErrorQueue: queue,
    addEventListener: (type, listener) => { listeners[type] = listener; },
    Matomo: { getAsyncTrackers: () => [tracker] },
  };
  if (observer) {
    window.ReportingObserver = function (callback, options) {
      env.observed = options;
      this.observe = () => { env.browserReports = callback; };
    };
  }
  const src = Deno.readTextFileSync(new URL("../../tracker.js", import.meta.url));
  new Function("window", "setTimeout", src)(window, (callback) => timers.push(callback));
  env.window = window;
  env.fire = (type, event) => listeners[type] && listeners[type](event);
  // Lets the time pass that the script waits for a tracker.
  env.wait = (times = 1) => { for (let i = 0; i < times && timers.length; i++) timers.shift()(); };
  return env;
}

const jsError = (message, extra = {}) => ({
  message, filename: "https://shop.example/app.js?v=2", lineno: 12, colno: 34,
  error: { stack: "Error: " + message + "\n    at run (https://shop.example/app.js:12:34)" }, ...extra,
});

Deno.test("a JavaScript error is sent with where it happened", () => {
  const env = load();
  env.fire("error", jsError("Uncaught Error: Boom", { target: env.window }));

  assertEquals(env.requests.length, 1);
  assertEquals(Object.fromEntries(env.requests[0]), {
    ca: "1", ret_t: "js", ret_m: "Uncaught Error: Boom", ret_s: "https://shop.example/app.js",
    ret_l: "12", ret_c: "34", ret_k: "Error: Uncaught Error: Boom\n    at run (https://shop.example/app.js:12:34)",
  });
});

Deno.test("an element that failed to load is sent with its address", () => {
  const env = load();
  env.fire("error", { target: { tagName: "IMG", src: "https://cdn.example/hero.png?size=2" } });

  assertEquals(env.requests[0].get("ret_t"), "resource");
  assertEquals(env.requests[0].get("ret_m"), "Failed to load img");
  assertEquals(env.requests[0].get("ret_s"), "https://cdn.example/hero.png");
});

Deno.test("an unhandled promise rejection is sent, with an error or with any other reason", () => {
  const env = load();
  env.fire("unhandledrejection", { reason: { name: "TypeError", message: "Failed to fetch", stack: "TypeError: Failed to fetch" } });
  env.fire("unhandledrejection", { reason: "timeout" });
  env.fire("unhandledrejection", { reason: undefined });

  assertEquals(env.requests.map((r) => [r.get("ret_t"), r.get("ret_m")]), [
    ["promise", "TypeError: Failed to fetch"],
    ["promise", "timeout"],
  ]);
});

Deno.test("the same error is sent once", () => {
  const env = load();
  env.fire("error", jsError("Boom"));
  env.fire("error", jsError("Boom"));
  env.fire("error", jsError("Boom", { lineno: 13 }));

  assertEquals(env.requests.length, 2);
});

Deno.test("a page that keeps throwing stops sending", () => {
  const env = load();
  for (let i = 0; i < 50; i++) env.fire("error", jsError("Boom " + i));

  assertEquals(env.requests.length, 10);
});

Deno.test("errors of browser extensions and known noise are not sent", () => {
  const env = load();
  env.fire("error", jsError("Boom", { filename: "chrome-extension://abc/content.js" }));
  env.fire("error", jsError("Boom", { filename: "", error: { stack: "at moz-extension://abc/x.js:1:1" } }));
  env.fire("error", jsError("ResizeObserver loop completed with undelivered notifications."));

  assertEquals(env.requests.length, 0);
});

Deno.test("long texts are cut", () => {
  const env = load();
  env.fire("error", jsError("m".repeat(5000), { error: { stack: "s".repeat(50000) } }));

  assertEquals(env.requests[0].get("ret_m").length, 500);
  assertEquals(env.requests[0].get("ret_k").length, 2000);
});

Deno.test("an error waits for a tracker that knows its website", () => {
  const env = load({ siteId: "" });
  env.fire("error", jsError("Boom"));
  env.fire("error", jsError("Bang"));
  env.wait(3);
  assertEquals(env.requests.length, 0);

  env.siteId = 7;
  env.wait();
  assertEquals(env.requests.map((r) => r.get("ret_m")), ["Boom", "Bang"]);
});

Deno.test("an error stops waiting when no tracker gets a website", () => {
  const env = load({ siteId: "" });
  env.fire("error", jsError("Boom"));
  env.wait(100);

  env.siteId = 7;
  env.wait(100);
  assertEquals(env.requests.length, 0);
});

Deno.test("the errors a snippet collected before the tracker was loaded are sent", () => {
  const queue = [
    jsError("Early error"),
    { type: "unhandledrejection", reason: "Early rejection" },
    { target: { tagName: "SCRIPT", src: "https://cdn.example/early.js" } },
    { type: "securitypolicyviolation", effectiveDirective: "img-src", blockedURI: "https://cdn.example/x.png" },
  ];
  const env = load({ queue });

  assertEquals(env.requests.map((r) => [r.get("ret_t"), r.get("ret_m")]), [
    ["js", "Early error"],
    ["promise", "Early rejection"],
    ["resource", "Failed to load script"],
    ["csp", "Content Security Policy: img-src"],
  ]);

  // The snippet keeps listening, what it collects from now on is not kept.
  env.window.rebelErrorQueue.push(jsError("Later"));
  assertEquals(env.requests.length, 4);
});

Deno.test("a Content Security Policy violation is sent with what was blocked", () => {
  const env = load();
  env.fire("securitypolicyviolation", {
    effectiveDirective: "script-src-elem", blockedURI: "https://evil.example/x.js?id=1", disposition: "enforce",
    sourceFile: "https://shop.example/", lineNumber: 4, columnNumber: 2,
  });
  env.fire("securitypolicyviolation", { violatedDirective: "img-src", blockedURI: "data", disposition: "report" });

  assertEquals(Object.fromEntries(env.requests[0]), {
    ca: "1", ret_t: "csp", ret_m: "Content Security Policy: script-src-elem", ret_s: "https://evil.example/x.js",
    ret_l: "4", ret_c: "2", ret_k: "at https://shop.example/:4:2",
  });
  assertEquals(env.requests[1].get("ret_m"), "Content Security Policy: img-src (report only)");
});

Deno.test("what the browser reports about the page is sent", () => {
  const env = load({ observer: true });
  env.browserReports([
    { type: "deprecation", body: { message: "Unload event listeners are deprecated", sourceFile: "https://shop.example/app.js", lineNumber: 3, columnNumber: 9 } },
    { type: "intervention", body: { message: "Blocked a slow script" } },
  ]);

  assertEquals(env.observed, { types: ["deprecation", "intervention"], buffered: true });
  assertEquals(env.requests.map((r) => [r.get("ret_t"), r.get("ret_m"), r.get("ret_s")]), [
    ["browser", "deprecation: Unload event listeners are deprecated", "https://shop.example/app.js"],
    ["browser", "intervention: Blocked a slow script", ""],
  ]);
});

Deno.test("an event that can not be read does not throw", () => {
  const env = load();
  env.window.Matomo = undefined;
  env.fire("error", jsError("Boom"));
  env.fire("unhandledrejection", null);

  assertEquals(env.requests.length, 0);
});

Deno.test("loading the tracker a second time does not listen twice", () => {
  const env = load({ loadedBefore: true });
  env.fire("error", jsError("Boom"));

  assertEquals(env.requests.length, 0);
});
