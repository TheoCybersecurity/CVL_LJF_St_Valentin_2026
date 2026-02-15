<?php
/**
 * Inclusions d'en-tête HTML (Head Imports)
 * head_imports.php
 * * Ce fichier centralise les balises méta techniques et les feuilles de styles (CSS).
 * Il est inclus dans la section <head> de chaque page pour garantir :
 * 1. L'encodage et le responsive design.
 * 2. Le chargement des frameworks (Bootstrap, FontAwesome).
 * 3. Le style personnalisé avec gestion du cache.
 */
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" href="/images/CVL_LJF_St_Valentin_2026.png" type="image/x-icon">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<link href="assets/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">