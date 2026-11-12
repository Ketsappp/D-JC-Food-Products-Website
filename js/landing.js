(function () {
    "use strict";

    // Best sellers: a looping carousel you drag (mouse or finger) to move.
    // It also slides on its own every 5 seconds (paused only while you drag).
    var root = document.getElementById("carousel");
    if (!root) return;

    var track = document.getElementById("slides");
    var slides = Array.prototype.slice.call(track.querySelectorAll(".slide"));
    var dots = Array.prototype.slice.call(root.querySelectorAll(".dot"));
    var INTERVAL = 5000;   // next product every 5 seconds
    var count = slides.length;

    if (count < 2) return;

    // Each slide takes its colours from its product photo, so the background matches the
    // product and the product stands out. (Slides without a photo keep their preset theme.)
    function productColour(img) {
        var size = 48, canvas = document.createElement("canvas");
        canvas.width = canvas.height = size;
        var ctx = canvas.getContext("2d", { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, size, size);
        var data = ctx.getImageData(0, 0, size, size).data;
        var sumX = 0, sumY = 0, weight = 0, satSum = 0;

        for (var i = 0; i < data.length; i += 4) {
            var r = data[i] / 255, g = data[i + 1] / 255, b = data[i + 2] / 255, a = data[i + 3] / 255;
            var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, d = max - min;
            if (a < 0.5 || d < 0.12 || l > 0.93 || l < 0.1) continue;   // skip see-through, grey, white and black

            var sat = d / (1 - Math.abs(2 * l - 1));
            var hue = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
            var angle = hue * Math.PI / 3, w = sat * sat;

            sumX += Math.cos(angle) * w; sumY += Math.sin(angle) * w;
            satSum += sat * w; weight += w;
        }

        if (weight < 1) return null;

        var h = Math.round(((Math.atan2(sumY, sumX) * 180 / Math.PI) + 360) % 360);
        return { h: h, s: Math.max(30, Math.min(80, Math.round(satSum / weight * 100))) };
    }

    function paintSlide(slide, c) {
        var st = slide.style;
        st.setProperty("--bg1", "hsl(" + c.h + "," + Math.min(c.s, 70) + "%,93%)");
        st.setProperty("--bg2", "hsl(" + c.h + "," + Math.min(c.s + 10, 85) + "%,79%)");
        st.setProperty("--glow", "hsla(" + c.h + ",90%,98%,.8)");
        st.setProperty("--ink", "hsl(" + c.h + ",45%,15%)");
        st.setProperty("--accent", "hsl(" + c.h + ",60%,30%)");
        st.setProperty("--plate", "hsl(" + c.h + ",70%,97%)");
        st.setProperty("--ring", "hsla(" + c.h + "," + Math.min(c.s + 15, 90) + "%,55%,.22)");
    }

    function colourSlides() {
        var all = Array.prototype.slice.call(track.querySelectorAll(".slide"));

        slides.forEach(function (slide, index) {
            var img = slide.querySelector(".slide-art img");
            if (!img) return;

            function apply() {
                try {
                    var c = productColour(img);
                    if (!c) return;
                    // The real slide and its loop copy share the same colours.
                    paintSlide(slide, c);
                    all.forEach(function (other) {
                        if (other !== slide && other.getAttribute("data-copy-of") === String(index)) paintSlide(other, c);
                    });
                } catch (err) { /* photo from another site: keep the preset theme */ }
            }

            if (img.complete && img.naturalWidth) apply(); else img.addEventListener("load", apply, { once: true });
        });
    }

    // Copies of the last and first slide on each side make the loop seamless.
    function makeClone(slide) {
        var copy = slide.cloneNode(true);
        copy.classList.remove("is-active");
        copy.setAttribute("aria-hidden", "true");
        copy.setAttribute("inert", "");
        return copy;
    }
    var cloneBefore = makeClone(slides[count - 1]), cloneAfter = makeClone(slides[0]);
    cloneBefore.setAttribute("data-copy-of", String(count - 1));
    cloneAfter.setAttribute("data-copy-of", "0");
    track.insertBefore(cloneBefore, slides[0]);
    track.appendChild(cloneAfter);
    colourSlides();

    var position = 1;          // index in the track (1 = first real slide)
    var width = root.clientWidth;
    var timer = null;
    var hovering = false;
    var dragging = false;
    var startX = 0, startY = 0, startOffset = 0, dragDx = 0, moved = false, lastX = 0, lastT = 0, velocity = 0;
    var locked = null;         // "x" once the gesture is clearly horizontal, "y" if it is a page scroll
    var onScreen = true;       // false while the carousel is scrolled out of view

    // The login / sign-up pop-up blurs the page behind it. Animating the carousel underneath would
    // force the browser to re-blur the whole screen every frame (that was the lag), so while the
    // pop-up is open, or the carousel is off-screen, it simply waits.
    function suspended() {
        return document.documentElement.classList.contains("am-locked") || !onScreen;
    }

    root.style.setProperty("--interval", INTERVAL + "ms");

    function realIndex() {
        return (position - 1 + count) % count;
    }

    function place(offset, animate) {
        track.classList.toggle("dragging", !animate);
        track.style.transform = "translate3d(" + offset + "px,0,0)";
    }

    function refreshState() {
        var current = realIndex();

        slides.forEach(function (slide, i) {
            var active = i === current;
            slide.classList.toggle("is-active", active);
            slide.setAttribute("aria-hidden", active ? "false" : "true");
            slide.querySelectorAll("a").forEach(function (a) {
                if (active) a.removeAttribute("tabindex"); else a.setAttribute("tabindex", "-1");
            });
        });

        dots.forEach(function (dot, i) {
            dot.classList.toggle("is-active", i === current);
            dot.setAttribute("aria-selected", i === current ? "true" : "false");
        });
    }

    function goTo(next, animate) {
        position = next;
        place(-position * width, animate !== false);

        // No animation means no transitionend, so settle on the real slide right away.
        if (animate === false) {
            if (position === 0) { position = count; place(-position * width, false); }
            else if (position === count + 1) { position = 1; place(-position * width, false); }
        }

        refreshState();
        restart();
    }

    // After sliding onto a copy, jump (without animation) to the real slide it copies.
    track.addEventListener("transitionend", function (e) {
        if (e.target !== track) return;
        if (position === 0) { position = count; place(-position * width, false); }
        else if (position === count + 1) { position = 1; place(-position * width, false); }
    });

    function stop() {
        clearTimeout(timer);
        root.classList.remove("is-playing");
    }

    function restart() {
        stop();
        if (hovering || dragging || document.hidden || suspended()) return;

        void root.offsetWidth;   // restart the progress animation on the active dot
        root.classList.add("is-playing");
        timer = setTimeout(function () { goTo(position + 1); }, INTERVAL);
    }

    // ----- drag / swipe -----
    root.addEventListener("pointerdown", function (e) {
        if (e.pointerType === "mouse" && e.button !== 0) return;
        if (e.target.closest(".dot")) return;

        dragging = true; moved = false; locked = null; dragDx = 0; velocity = 0;
        startX = lastX = e.clientX; startY = e.clientY; lastT = performance.now();
        startOffset = -position * width;

        // Dragging while it is mid-slide or on a copy: settle first.
        if (position === 0) { position = count; startOffset = -position * width; }
        else if (position === count + 1) { position = 1; startOffset = -position * width; }
        stop();
    });

    root.addEventListener("pointermove", function (e) {
        if (!dragging) return;

        var dx = e.clientX - startX, dy = e.clientY - startY;

        if (locked === null && (Math.abs(dx) > 6 || Math.abs(dy) > 6)) {
            locked = Math.abs(dx) > Math.abs(dy) ? "x" : "y";

            if (locked === "x") {
                try { root.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
            }
        }

        if (locked !== "x") return;

        var now = performance.now();
        velocity = (e.clientX - lastX) / Math.max(1, now - lastT);
        lastX = e.clientX; lastT = now;
        dragDx = dx; moved = true;
        place(startOffset + dx, false);
        root.classList.add("is-dragging");
    });

    function endDrag(e) {
        if (!dragging) return;
        dragging = false;
        root.classList.remove("is-dragging");

        if (!moved) { restart(); return; }

        // Far enough, or a quick flick, moves one slide; otherwise it springs back.
        var step = 0;
        if (dragDx < -width * 0.18 || velocity < -0.5) step = 1;
        else if (dragDx > width * 0.18 || velocity > 0.5) step = -1;

        goTo(position + step);
    }

    root.addEventListener("pointerup", endDrag);
    root.addEventListener("pointercancel", endDrag);

    // A drag must not "click" the button or link it ended on.
    root.addEventListener("click", function (e) {
        if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
    }, true);

    dots.forEach(function (dot) {
        dot.addEventListener("click", function () { goTo(parseInt(dot.dataset.index, 10) + 1); });
    });

    // It keeps sliding while the mouse rests on it; only dragging or a hidden tab pauses it.
    document.addEventListener("visibilitychange", restart);

    // Pause / resume when the pop-up opens or closes (it sets the "am-locked" class on <html>).
    if ("MutationObserver" in window) {
        new MutationObserver(restart).observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
    }

    // Pause while the carousel is scrolled out of view.
    if ("IntersectionObserver" in window) {
        new IntersectionObserver(function (entries) {
            onScreen = entries[entries.length - 1].isIntersecting;
            restart();
        }, { threshold: 0.05 }).observe(root);
    }

    // Keyboard: arrow keys while the carousel has focus.
    root.addEventListener("keydown", function (e) {
        if (e.key === "ArrowLeft") { goTo(position - 1); }
        else if (e.key === "ArrowRight") { goTo(position + 1); }
    });

    window.addEventListener("resize", function () {
        width = root.clientWidth;
        place(-position * width, false);
    });

    place(-position * width, false);
    refreshState();
    restart();
})();

/* ---------- Sneak peek: category buttons work as a filter ---------- */
(function () {
    "use strict";

    var bar = document.getElementById("peekFilters");
    if (!bar) return;

    var buttons = Array.prototype.slice.call(bar.querySelectorAll(".chip"));
    var cards = Array.prototype.slice.call(document.querySelectorAll(".peek-grid .card"));
    var more = document.getElementById("peekMore");
    var title = document.getElementById("peekTitle");
    // The menu address comes from data-open-href, because while the store is
    // closed the link has no href at all (store_hours.js puts it back later).
    var baseHref = more ? (more.getAttribute("data-open-href") || more.getAttribute("href") || "") : "";

    function apply(filter) {
        buttons.forEach(function (btn) {
            var on = btn.dataset.filter === filter;
            btn.classList.toggle("is-active", on);
            btn.setAttribute("aria-pressed", on ? "true" : "false");
        });

        cards.forEach(function (card) {
            // "All" shows the handpicked sample; a category shows everything in it.
            var show = filter === "all" ? card.dataset.default === "1" : card.dataset.category === filter;
            card.hidden = !show;
        });

        if (title) title.textContent = filter === "all" ? "A taste of our menu" : filter;

        if (more) {
            var openHref = filter === "all" ? baseHref : baseHref + "?category=" + encodeURIComponent(filter);
            var openLabel = filter === "all" ? "See the Full Menu" : "Order from " + filter;

            // Remember the normal link and text; show them only while the store is open.
            more.setAttribute("data-open-href", openHref);
            more.setAttribute("data-open-label", openLabel);

            if (window.storeIsOpen !== false) {
                more.setAttribute("href", openHref);
                more.textContent = openLabel;
            }
        }
    }

    buttons.forEach(function (btn) {
        btn.addEventListener("click", function () { apply(btn.dataset.filter); });
    });

    apply("all");
})();

/* ---------- Round 3: scroll reveal, image skeletons, review scroller ---------- */
(function () {
    "use strict";

    // 1. Sections fade up the first time they scroll into view.
    //    (The "js" class on <html> is added in the page head, so without JavaScript everything just shows.)
    var sections = Array.prototype.slice.call(document.querySelectorAll(".reveal"));

    if (sections.length) {
        if (!("IntersectionObserver" in window)) {
            sections.forEach(function (el) { el.classList.add("in"); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("in");
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: "0px 0px -6% 0px" });

            sections.forEach(function (el) { io.observe(el); });

            // Safety net: never leave content hidden if the observer does not fire.
            setTimeout(function () { sections.forEach(function (el) { el.classList.add("in"); }); }, 4000);
        }
    }

    // 2. Product photos show a shimmer until they have loaded.
    Array.prototype.forEach.call(document.querySelectorAll(".card-media img"), function (img) {
        if (img.complete) return;
        var box = img.parentNode;
        box.classList.add("is-loading");
        function done() { box.classList.remove("is-loading"); }
        img.addEventListener("load", done, { once: true });
        img.addEventListener("error", done, { once: true });
    });

    // 3. Reviews: arrows appear only when there are more reviews than fit.
    var track = document.getElementById("fbTrack");
    if (track) {
        var prev = document.querySelector(".fb-prev"), next = document.querySelector(".fb-next");

        function sync() {
            var more = track.scrollWidth - track.clientWidth > 4;
            if (prev) prev.hidden = !more || track.scrollLeft < 4;
            if (next) next.hidden = !more || track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
        }
        function step(dir) {
            track.scrollBy({ left: dir * Math.max(280, track.clientWidth * 0.8), behavior: "smooth" });
        }

        if (prev) prev.addEventListener("click", function () { step(-1); });
        if (next) next.addEventListener("click", function () { step(1); });
        track.addEventListener("scroll", sync, { passive: true });
        window.addEventListener("resize", sync);
        sync();
    }
})();
