(function () {
    'use strict';

    var root = window.MillionDollarScript = window.MillionDollarScript || {};
    root.extensions = root.extensions || {};
    var extension = root.extensions.localMoneyMap = root.extensions.localMoneyMap || {};

    function unavailable(target) {
        var config = extension.config || {};
        target.textContent = config.unavailable || 'The interactive map is unavailable. Sponsor locations remain listed below.';
        target.classList.add('is-unavailable');
    }

    function validSpot(spot) {
        var latitude = Number(spot.latitude);
        var longitude = Number(spot.longitude);

        return Number.isFinite(latitude)
            && Number.isFinite(longitude)
            && latitude >= -90
            && latitude <= 90
            && longitude >= -180
            && longitude <= 180;
    }

    function initialize(target) {
        var dataNode = target.nextElementSibling;
        var spots;

        if (!window.ol || !dataNode || !dataNode.classList.contains('mds-local-money-map__map-data')) {
            unavailable(target);
            return;
        }

        try {
            spots = JSON.parse(dataNode.textContent || '[]').filter(validSpot);
        } catch (error) {
            unavailable(target);
            return;
        }

        if (!spots.length) {
            unavailable(target);
            return;
        }

        var markerStyle = new window.ol.style.Style({
            image: new window.ol.style.Circle({
                radius: 8,
                fill: new window.ol.style.Fill({ color: '#b4235a' }),
                stroke: new window.ol.style.Stroke({ color: '#ffffff', width: 3 })
            })
        });
        var features = spots.map(function (spot) {
            var feature = new window.ol.Feature({
                geometry: new window.ol.geom.Point(window.ol.proj.fromLonLat([
                    Number(spot.longitude),
                    Number(spot.latitude)
                ])),
                spotId: Number(spot.id),
                title: String(spot.title || ''),
                location: String(spot.location || '')
            });
            feature.setStyle(markerStyle);
            return feature;
        });
        var vectorSource = new window.ol.source.Vector({ features: features });

        target.textContent = '';
        var map = new window.ol.Map({
            target: target,
            layers: [
                new window.ol.layer.Tile({ source: new window.ol.source.OSM() }),
                new window.ol.layer.Vector({ source: vectorSource })
            ],
            view: new window.ol.View({
                center: features[0].getGeometry().getCoordinates(),
                zoom: 13
            })
        });

        if (features.length > 1) {
            map.getView().fit(vectorSource.getExtent(), {
                padding: [48, 48, 48, 48],
                maxZoom: 14,
                size: map.getSize()
            });
        }

        map.on('singleclick', function (event) {
            var feature = map.forEachFeatureAtPixel(event.pixel, function (candidate) {
                return candidate;
            });
            if (!feature) {
                return;
            }

            var card = document.getElementById('mds-lmm-spot-' + feature.get('spotId'));
            if (!card) {
                return;
            }
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            card.focus({ preventScroll: true });
            card.classList.add('is-map-selected');
            window.setTimeout(function () {
                card.classList.remove('is-map-selected');
            }, 2500);
        });

        map.on('pointermove', function (event) {
            target.style.cursor = map.hasFeatureAtPixel(event.pixel) ? 'pointer' : '';
        });

        if ('ResizeObserver' in window) {
            new window.ResizeObserver(function () {
                map.updateSize();
            }).observe(target);
        }
    }

    function ready() {
        document.querySelectorAll('[data-mds-local-money-map]').forEach(initialize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
}());
