#target photoshop

(function () {
    var oldUnits = app.preferences.rulerUnits;
    app.displayDialogs = DialogModes.NO;
    app.preferences.rulerUnits = Units.PIXELS;

    try {
        var root = new File($.fileName).parent.parent.parent.fsName + '/';
        var out = root + 'design/luna-card/';
        var backgroundFile = new File(out + 'material3-background-generated.png');
        for (var i = app.documents.length - 1; i >= 0; i--) {
            try {
                if (app.documents[i].fullName.fsName === backgroundFile.fsName) {
                    app.documents[i].close(SaveOptions.DONOTSAVECHANGES);
                }
            } catch (ignored) {}
        }
        var document = app.open(backgroundFile);
        document.crop([UnitValue(110, 'px'), UnitValue(145, 'px'), UnitValue(1463, 'px'), UnitValue(855, 'px')]);
        document.resizeImage(UnitValue(1200, 'px'), UnitValue(630, 'px'), 72, ResampleMethod.BICUBICSHARPER);
        document.activeLayer.name = 'Generated M3 tonal background';

        var logoDocument = app.open(new File(root + 'assets/images/ogp-logo.png'));
        logoDocument.trim(TrimType.TRANSPARENT, true, true, true, true);
        logoDocument.selection.selectAll();
        logoDocument.selection.copy();
        logoDocument.close(SaveOptions.DONOTSAVECHANGES);

        app.activeDocument = document;
        document.paste();
        var logoLayer = document.activeLayer;
        logoLayer.name = 'Original Luminous Core emblem';
        var logoWidth = logoLayer.bounds[2].as('px') - logoLayer.bounds[0].as('px');
        var scale = 190 / logoWidth * 100;
        logoLayer.resize(scale, scale, AnchorPosition.MIDDLECENTER);
        logoLayer.translate(275 - logoLayer.bounds[0].as('px'), 240 - logoLayer.bounds[1].as('px'));

        var black = new SolidColor();
        black.rgb.hexValue = '101010';
        var shadowColor = new SolidColor();
        shadowColor.rgb.hexValue = '54250D';

        function addDinLine(name, baseline) {
            var shadow = document.artLayers.add();
            shadow.kind = LayerKind.TEXT;
            shadow.name = 'Soft drop shadow ' + name;
            shadow.textItem.contents = name;
            shadow.textItem.font = 'DINAlternate-Bold';
            shadow.textItem.size = UnitValue(82, 'pt');
            shadow.textItem.position = [500, baseline + 4];
            shadow.textItem.color = shadowColor;
            shadow.textItem.justification = Justification.LEFT;
            shadow.textItem.fauxBold = true;
            shadow.rasterize(RasterizeType.ENTIRELAYER);
            shadow.applyGaussianBlur(6);
            shadow.opacity = 16;

            var layer = document.artLayers.add();
            layer.kind = LayerKind.TEXT;
            layer.name = 'DIN Alternate Bold ' + name;
            layer.textItem.contents = name;
            layer.textItem.font = 'DINAlternate-Bold';
            layer.textItem.size = UnitValue(82, 'pt');
            layer.textItem.position = [500, baseline];
            layer.textItem.color = black;
            layer.textItem.justification = Justification.LEFT;
            layer.textItem.fauxBold = true;
            return layer;
        }

        addDinLine('LUMINOUS', 310);
        addDinLine('CORE', 408);

        var psdOptions = new PhotoshopSaveOptions();
        psdOptions.layers = true;
        document.saveAs(new File(out + 'luminous-core-material3-center-1200x630.psd'), psdOptions, true, Extension.LOWERCASE);

        document.saveAs(new File(out + 'luminous-core-material3-center-1200x630.png'), new PNGSaveOptions(), true, Extension.LOWERCASE);
        document.close(SaveOptions.DONOTSAVECHANGES);
    } finally {
        app.preferences.rulerUnits = oldUnits;
        app.displayDialogs = DialogModes.ALL;
    }
}());
