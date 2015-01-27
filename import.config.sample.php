<?php return array(

    'creator_user' => 'admin',

    'create_images_inside_node' => 8339,

    'create_imported_nodes_inside_node' => 8339,

    'mapping' => array(
        'contentclass' => 'news_article',

        'fields' => array(

            'title' => 'title', // <-- no call back, no filtering needed. just set the attribute of the xml field.

            'body' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
                    return $apliaCliImporter->htmlParser->parse((string)$xmlNode->body);
                },
            'intro' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
                    return $apliaCliImporter->htmlParser->parse((string)$xmlNode->intro);
                }
        )
    )
);

