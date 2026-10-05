#target photoshop

(function () {
    var oldUnits = app.preferences.rulerUnits;
    app.displayDialogs = DialogModes.NO;
    app.preferences.rulerUnits = Units.PIXELS;

    try {
        var root = new File($.fileName).parent.parent.parent.fsName + '/';
        var out = root + 'design/luna-card/';
        var background = new File(out + 'material3-background-generated.png');
        var logo = new File(root + 'assets/images/ogp-logo.png');
        var document = app.open(background);
        document.resizeImage(UnitValue(318, 'px'), UnitValue(202, 'px'), 72, ResampleMethod.BICUBICSHARPER);
        document.activeLayer.name = 'Generated M3 tonal background';

        var logoDocument = app.open(logo);
        logoDocument.trim(TrimType.TRANSPARENT, true, true, true, true);
        logoDocument.selection.selectAll();
        logoDocument.selection.copy();
        logoDocument.close(SaveOptions.DONOTSAVECHANGES);

        app.activeDocument = document;
        document.paste();
        var logoLayer = document.activeLayer;
        logoLayer.name = 'Original Luminous Core emblem';
        var logoWidth = logoLayer.bounds[2].as('px') - logoLayer.bounds[0].as('px');
        var scale = 76 / logoWidth * 100;
        logoLayer.resize(scale, scale, AnchorPosition.MIDDLECENTER);
        logoLayer.translate(18 - logoLayer.bounds[0].as('px'), 76 - logoLayer.bounds[1].as('px'));

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
            shadow.textItem.size = UnitValue(28, 'pt');
            shadow.textItem.position = [134, baseline + 2];
            shadow.textItem.color = shadowColor;
            shadow.textItem.justification = Justification.LEFT;
            shadow.textItem.fauxBold = true;
            shadow.rasterize(RasterizeType.ENTIRELAYER);
            shadow.applyGaussianBlur(3);
            shadow.opacity = 20;

            var layer = document.artLayers.add();
            layer.kind = LayerKind.TEXT;
            layer.name = 'DIN Alternate Bold ' + name;
            layer.textItem.contents = name;
            layer.textItem.font = 'DINAlternate-Bold';
            layer.textItem.size = UnitValue(28, 'pt');
            layer.textItem.position = [134, baseline];
            layer.textItem.color = black;
            layer.textItem.justification = Justification.LEFT;
            layer.textItem.fauxBold = true;
            return layer;
        }

        addDinLine('LUMINOUS', 89);
        addDinLine('CORE', 122);

        var psdOptions = new PhotoshopSaveOptions();
        psdOptions.layers = true;
        document.saveAs(new File(out + 'luminous-core-material3-318x202.psd'), psdOptions, true, Extension.LOWERCASE);

        var pngOptions = new PNGSaveOptions();
        document.saveAs(new File(out + 'luminous-core-material3-318x202.png'), pngOptions, true, Extension.LOWERCASE);
        document.close(SaveOptions.DONOTSAVECHANGES);

    } catch (error) {
        var log = new File(out + 'photoshop-error.txt');
        log.open('w');
        log.write('line ' + error.line + ': ' + error.message);
        log.close();
        throw error;
    } finally {
        app.preferences.rulerUnits = oldUnits;
        app.displayDialogs = DialogModes.ALL;
    }
}());
