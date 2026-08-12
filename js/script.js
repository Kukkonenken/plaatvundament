(function () {
  "use strict";

  var nav = document.getElementById("site-nav");
  var openBtn = document.getElementById("nav-open");
  var closeBtn = document.getElementById("nav-close");
  var mobilePanel = document.getElementById("mobile-nav");
  var yearEl = document.getElementById("year");

  if (yearEl) {
    yearEl.textContent = String(new Date().getFullYear());
  }

  function setNavSolid() {
    if (!nav) return;
    if (window.scrollY > 24) {
      nav.classList.add("shadow-lg", "bg-slate-950/95", "backdrop-blur-md");
      nav.classList.remove("bg-slate-950/80");
    } else {
      nav.classList.remove("shadow-lg", "bg-slate-950/95", "backdrop-blur-md");
      nav.classList.add("bg-slate-950/80");
    }
  }

  setNavSolid();
  window.addEventListener("scroll", setNavSolid, { passive: true });

  function closeMobile() {
    if (!mobilePanel) return;
    mobilePanel.classList.add("hidden");
    mobilePanel.setAttribute("aria-hidden", "true");
    document.body.classList.remove("overflow-hidden");
    if (openBtn) openBtn.setAttribute("aria-expanded", "false");
  }

  function openMobile() {
    if (!mobilePanel) return;
    mobilePanel.classList.remove("hidden");
    mobilePanel.setAttribute("aria-hidden", "false");
    document.body.classList.add("overflow-hidden");
    if (openBtn) openBtn.setAttribute("aria-expanded", "true");
  }

  if (openBtn) openBtn.addEventListener("click", openMobile);
  if (closeBtn) closeBtn.addEventListener("click", closeMobile);
  if (mobilePanel) {
    mobilePanel.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", closeMobile);
    });
  }

  document.querySelectorAll(".reveal").forEach(function (el) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            observer.unobserve(entry.target);
          }
        });
      },
      { rootMargin: "0px 0px -8% 0px", threshold: 0.08 }
    );
    observer.observe(el);
  });

  function buildQuoteMessage(form) {
    var locEl = form.querySelector('[name="location"]');
    var m2El = form.querySelector('[name="project_m2"]');
    var msgEl = form.querySelector('[name="message"]');
    var loc = locEl && locEl.value ? locEl.value.trim() : "";
    var m2 = m2El && m2El.value ? m2El.value.trim() : "";
    var msg = msgEl && msgEl.value ? msgEl.value.trim() : "";
    var parts = [];
    if (loc) parts.push("Asukoht: " + loc);
    if (m2) parts.push("Projekti pindala: " + m2 + " m²");
    if (msg) parts.push(msg);
    var body = parts.join("\n\n");
    return body || "Päring plaatvundament.com avalehelt.";
  }

  var form = document.getElementById("contact-form");
  var quoteForm = document.getElementById("quote-form");
  var formStatus = document.getElementById("form-status");
  var quoteStatus = document.getElementById("quote-status");

  function wireForm(f, statusEl) {
    if (!f || !statusEl) return;
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      statusEl.textContent = "Saadan…";
      statusEl.className = "mt-4 text-sm text-slate-600";

      var fd = new FormData(f);
      if (f.id === "quote-form") {
        fd.set("message", buildQuoteMessage(f));
        fd.delete("location");
        fd.delete("project_m2");
      }

      var xhr = new XMLHttpRequest();
      xhr.open("POST", f.action || "send.php");
      xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      xhr.onload = function () {
        var ok = xhr.status >= 200 && xhr.status < 300;
        try {
          var data = JSON.parse(xhr.responseText);
          if (data && data.ok) ok = true;
        } catch (_) {
          ok = xhr.status === 200 && xhr.responseText.indexOf("ok") !== -1;
        }
        if (ok) {
          statusEl.textContent = "Täname. Sõnum on kätte saadud. Võtame ühendust tööpäevade jooksul.";
          statusEl.className = "mt-4 text-sm text-emerald-700";
          f.reset();
        } else {
          statusEl.textContent =
            "Saatmine ebaõnnestus. Proovige uuesti või helistage +372 5624 2122.";
          statusEl.className = "mt-4 text-sm text-red-600";
        }
      };
      xhr.onerror = function () {
        statusEl.textContent = "Ühendus ei õnnestunud. Kirjutage info@plaatvundament.com.";
        statusEl.className = "mt-4 text-sm text-red-600";
      };
      xhr.send(fd);
    });
  }

  wireForm(form, formStatus);
  wireForm(quoteForm, quoteStatus);

  var calcArea = document.getElementById("calc-area");
  var calcSoil = document.getElementById("calc-soil");
  var calcOut = document.getElementById("calc-output");
  var calcHeatRadios = document.querySelectorAll('input[name="calc-heat"]');
  if (calcArea && calcSoil && calcOut && calcHeatRadios.length) {
    function floorHeatingOn() {
      var el = document.querySelector('input[name="calc-heat"]:checked');
      return el && el.value === "yes";
    }
    function runCalc() {
      var area = parseFloat(calcArea.value, 10);
      if (!area || area < 10) {
        calcOut.textContent = "Sisestage pindala (vähemalt 10 m²).";
        return;
      }
      var mult = parseFloat(calcSoil.value, 10) || 1;
      var heatAdd = floorHeatingOn() ? 14 : 0;
      var base = 88;
      var low = Math.round(area * base * mult + area * heatAdd * 0.92);
      var high = Math.round(area * (base + 22) * mult + area * heatAdd * 1.08);
      calcOut.innerHTML =
        "Orienteeruv vahemik: <strong class=\"font-semibold text-white\">" +
        low.toLocaleString("et-EE") +
        " – " +
        high.toLocaleString("et-EE") +
        " €</strong> (KM) ilma käibemaksuta. Lõplik summa tuleneb lepingus fikseeritud mahust.";
    }
    calcArea.addEventListener("input", runCalc);
    calcSoil.addEventListener("change", runCalc);
    calcHeatRadios.forEach(function (r) {
      r.addEventListener("change", runCalc);
    });
    runCalc();
  }

  function initCarousels() {
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var duration = reduceMotion ? 0 : 380;

    document.querySelectorAll("[data-carousel]").forEach(function (root) {
      var viewport = root.querySelector(".carousel-viewport");
      var track = root.querySelector(".carousel-track");
      var slides = track ? track.querySelectorAll(".carousel-slide") : [];
      var prevBtn = root.querySelector("[data-carousel-prev]");
      var nextBtn = root.querySelector("[data-carousel-next]");
      var dotsWrap = root.querySelector("[data-carousel-dots]");
      var live = root.querySelector("[data-carousel-live]");
      if (!viewport || !track || slides.length === 0) return;

      var i = 0;
      var n = slides.length;

      function announce(text) {
        if (live) live.textContent = text;
      }

      function setDots() {
        if (!dotsWrap) return;
        dotsWrap.innerHTML = "";
        for (var d = 0; d < n; d++) {
          (function (idx) {
            var b = document.createElement("button");
            b.type = "button";
            b.className =
              "carousel-dot h-2.5 w-2.5 rounded-full border-0 transition " +
              (idx === i ? "bg-accent w-8" : "bg-slate-300 hover:bg-slate-400");
            b.setAttribute("aria-label", "Viide " + (idx + 1) + " / " + n);
            if (idx === i) b.setAttribute("aria-current", "true");
            b.addEventListener("click", function () {
              go(idx, true);
            });
            dotsWrap.appendChild(b);
          })(d);
        }
      }

      function go(idx) {
        i = ((idx % n) + n) % n;
        var offset = -i * 100;
        track.style.transitionDuration = duration ? duration + "ms" : "0ms";
        track.style.transform = "translateX(" + offset + "%)";
        slides.forEach(function (s, j) {
          s.setAttribute("aria-hidden", j === i ? "false" : "true");
        });
        if (prevBtn) prevBtn.disabled = n <= 1;
        if (nextBtn) nextBtn.disabled = n <= 1;
        setDots();
        var capStrip = root.querySelector("[data-carousel-caption]");
        if (capStrip) {
          var lbl = slides[i].getAttribute("data-slide-label");
          if (lbl) {
            capStrip.innerHTML = "<strong>Etapp " + (i + 1) + "/" + n + ".</strong> " + lbl;
          } else {
            capStrip.textContent = "";
          }
        }
        var cap = slides[i].querySelector("img");
        var capText = cap && cap.getAttribute("alt") ? cap.getAttribute("alt") : "Pilt " + (i + 1);
        announce(capText + ", " + (i + 1) + " / " + n);
      }

      if (prevBtn)
        prevBtn.addEventListener("click", function () {
          go(i - 1);
        });
      if (nextBtn)
        nextBtn.addEventListener("click", function () {
          go(i + 1);
        });

      root.addEventListener("keydown", function (e) {
        if (e.key === "ArrowLeft") {
          e.preventDefault();
          go(i - 1);
        }
        if (e.key === "ArrowRight") {
          e.preventDefault();
          go(i + 1);
        }
      });

      var sx = 0;
      var dragging = false;
      viewport.addEventListener(
        "touchstart",
        function (e) {
          if (!e.touches[0]) return;
          sx = e.touches[0].clientX;
          dragging = true;
        },
        { passive: true }
      );
      viewport.addEventListener(
        "touchend",
        function (e) {
          if (!dragging) return;
          dragging = false;
          var end = e.changedTouches[0] && e.changedTouches[0].clientX;
          if (end === undefined) return;
          var dx = end - sx;
          if (dx > 50) go(i - 1);
          else if (dx < -50) go(i + 1);
        },
        { passive: true }
      );

      track.style.display = "flex";
      track.style.width = n * 100 + "%";
      slides.forEach(function (s) {
        s.style.width = 100 / n + "%";
        s.style.flexShrink = "0";
      });

      go(0);
    });
  }

  initCarousels();
})();
