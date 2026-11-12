/* D&JC landing: fix hero product photos that were cropped when they were cut out.
   - Cropped on 2+ sides (e.g. pizza): clipped into a clean circle on the plate  (.is-cropped)
   - Cut on one side (e.g. cookies sliced off at the edge): that edge fades softly (.is-cut)
   Clean cut-outs with transparent margins (cups, cheesecake) are left untouched.
   Detection is automatic, so newly uploaded photos are handled too. */
(function () {
    "use strict";

    var SIDES = ["left", "right", "top", "bottom"];
    var TOWARD = { left: "to left", right: "to right", top: "to top", bottom: "to bottom" };   // fade runs toward the cut edge
    var FADE = 18;   // % of the photo that fades out at a cut edge

    // How much of each edge is opaque: [left, right, top, bottom], 0..1
    function edgeCoverage(img) {
        var n = 48, c = document.createElement("canvas");
        c.width = c.height = n;
        var ctx = c.getContext("2d", { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, n, n);
        var d = ctx.getImageData(0, 0, n, n).data, e = [0, 0, 0, 0], i;
        function opaque(x, y) { return d[(y * n + x) * 4 + 3] > 128; }
        for (i = 0; i < n; i++) {
            if (opaque(0, i)) e[0]++;
            if (opaque(n - 1, i)) e[1]++;
            if (opaque(i, 0)) e[2]++;
            if (opaque(i, n - 1)) e[3]++;
        }
        return e.map(function (v) { return v / n; });
    }

    function fit(img) {
        var e, i, heavy = 0, cut = [];
        try { e = edgeCoverage(img); } catch (err) { return; }   // unreadable: keep normal look
        for (i = 0; i < 4; i++) {
            if (e[i] > 0.3) heavy++;
            if (e[i] > 0.1) cut.push(SIDES[i]);
        }
        if (!cut.length) return;                                  // clean cut-out

        if (heavy >= 2) { img.classList.add("is-cropped"); return; }

        // soft fade on every cut edge (masks are intersected)
        var layers = cut.map(function (s) {
            return "linear-gradient(" + TOWARD[s] + ", #000 " + (100 - FADE) + "%, transparent 100%)";
        }).join(",");
        img.style.webkitMaskImage = layers;
        img.style.maskImage = layers;
        img.style.webkitMaskComposite = "source-in";
        img.style.maskComposite = "intersect";
        img.classList.add("is-cut");
    }

    Array.prototype.forEach.call(document.querySelectorAll(".slide-art img"), function (img) {
        if (img.complete && img.naturalWidth) fit(img);
        else img.addEventListener("load", function () { fit(img); }, { once: true });
    });
})();
