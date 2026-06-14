<?php

use App\Services\WhatsApp\DriveFileSemanticMetadataService;

it('enriquece labels de imagem em ingles para busca em portugues', function () {
    $metadata = app(DriveFileSemanticMetadataService::class)->build(
        title: 'IMG_2042',
        fileName: 'IMG_2042.png',
        folderName: 'Fotos / Viagens',
        kind: 'image',
        labels: ['Snow', 'Mountain', 'Landscape'],
        extractedText: null,
    );

    expect($metadata['description'])->toContain('Imagem IMG_2042')
        ->and($metadata['description'])->toContain('Fotos / Viagens')
        ->and($metadata['tags'])->toContain('neve')
        ->and($metadata['tags'])->toContain('montanha')
        ->and($metadata['tags'])->toContain('viagem');
});

it('enriquece documentos por texto extraido e contexto de pasta', function () {
    $metadata = app(DriveFileSemanticMetadataService::class)->build(
        title: 'recibo_oficina',
        fileName: 'recibo_oficina.pdf',
        folderName: 'Comprovantes / Veiculos',
        kind: 'document',
        labels: [],
        extractedText: 'Servico mecanico realizado na oficina com troca de oleo do carro.',
    );

    expect($metadata['description'])->toContain('Documento recibo_oficina')
        ->and($metadata['description'])->toContain('Servico mecanico')
        ->and($metadata['tags'])->toContain('comprovante')
        ->and($metadata['tags'])->toContain('mecanico')
        ->and($metadata['tags'])->toContain('oficina')
        ->and($metadata['tags'])->toContain('veiculo');
});
