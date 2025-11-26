<?php

// Unified sort: "name" is logical field, not DB column
$sort = "name";
$order = "ASC";

require_once "includes/inc_all_client.php";

// Folder
if (!empty($_GET['folder_id'])) {
    $folder_id = intval($_GET['folder_id']);
} else {
    $folder_id = 0;
}

// Folder ID (used in forms/etc)
$get_folder_id = $folder_id;

// View Mode -- 0 List, 1 Thumbnail (thumbnail = files only)
if (!empty($_GET['view'])) {
    $view = intval($_GET['view']);
} else {
    $view = 0;
}

if (!isset($q)) {
    $q = '';
}

// ---------------------------------------------
// Breadcrumbs: build full folder path
// ---------------------------------------------
$folder_path = [];
$breadcrumb_folder_id = $get_folder_id;

while ($breadcrumb_folder_id > 0) {
    $sql_folder = mysqli_query($mysqli, "SELECT folder_name, parent_folder FROM folders WHERE folder_id = $breadcrumb_folder_id AND folder_client_id = $client_id");
    if ($row_folder = mysqli_fetch_assoc($sql_folder)) {
        $folder_name = nullable_htmlentities($row_folder['folder_name']);
        $parent_folder = intval($row_folder['parent_folder']);

        array_unshift($folder_path, [
            'folder_id'   => $breadcrumb_folder_id,
            'folder_name' => $folder_name
        ]);

        $breadcrumb_folder_id = $parent_folder;
    } else {
        break;
    }
}

// ---------------------------------------------
// Helper: unified folder tree (no folder_location)
// ---------------------------------------------
function is_ancestor_folder($folder_id, $current_folder_id, $client_id) {
    global $mysqli;

    if ($current_folder_id == 0) {
        return false;
    }
    if ($current_folder_id == $folder_id) {
        return true;
    }

    $result = mysqli_query($mysqli, "SELECT parent_folder FROM folders WHERE folder_id = $current_folder_id AND folder_client_id = $client_id");
    if ($row = mysqli_fetch_assoc($result)) {
        $parent_folder_id = intval($row['parent_folder']);
        return is_ancestor_folder($folder_id, $parent_folder_id, $client_id);
    } else {
        return false;
    }
}

function display_folders($parent_folder_id, $client_id, $indent = 0) {
    global $mysqli, $get_folder_id, $session_user_role;

    $sql_folders = mysqli_query($mysqli, "SELECT * FROM folders WHERE parent_folder = $parent_folder_id AND folder_client_id = $client_id ORDER BY folder_name ASC");
    while ($row = mysqli_fetch_array($sql_folders)) {
        $folder_id   = intval($row['folder_id']);
        $folder_name = nullable_htmlentities($row['folder_name']);

        // Count files in folder
        $row_files = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('file_id') AS num FROM files WHERE file_folder_id = $folder_id AND file_client_id = $client_id AND file_archived_at IS NULL"));
        $num_files = intval($row_files['num']);

        // Count documents in folder
        $row_docs = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('document_id') AS num FROM documents WHERE document_folder_id = $folder_id AND document_client_id = $client_id AND document_archived_at IS NULL"));
        $num_docs = intval($row_docs['num']);

        $num_total = $num_files + $num_docs;

        // Count subfolders
        $subfolder_result = mysqli_query($mysqli, "SELECT COUNT(*) AS count FROM folders WHERE parent_folder = $folder_id AND folder_client_id = $client_id");
        $subfolder_count  = intval(mysqli_fetch_assoc($subfolder_result)['count']);

        echo '<li class="nav-item">';
        echo '<div class="row">';
        echo '<div class="col-10">';
        echo '<a class="nav-link ';
        if ($get_folder_id == $folder_id) { echo "active"; }
        echo '" href="?client_id=' . $client_id . '&folder_id=' . $folder_id . '">';

        echo str_repeat('&nbsp;', $indent * 4);

        if ($get_folder_id == $folder_id || is_ancestor_folder($folder_id, $get_folder_id, $client_id)) {
            echo '<i class="fas fa-fw fa-folder-open"></i>';
        } else {
            echo '<i class="fas fa-fw fa-folder"></i>';
        }

        echo ' ' . $folder_name;

        if ($num_total > 0) {
            echo "<span class='badge badge-pill badge-dark float-right mt-1'>$num_total</span>";
        }

        echo '</a>';
        echo '</div>';
        echo '<div class="col-2">';
        ?>
        <div class="dropdown">
            <button class="btn btn-sm" type="button" data-toggle="dropdown">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="dropdown-menu">
                <a class="dropdown-item ajax-modal" href="#"
                    data-modal-url="modals/folder/folder_rename.php?id=<?= $folder_id ?>">
                    <i class="fas fa-fw fa-edit mr-2"></i>Rename
                </a>
                <?php
                // Only show delete if admin and no contents and no subfolders
                if ($session_user_role == 3 && $num_total == 0 && $subfolder_count == 0) { ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_folder=<?php echo $folder_id; ?>">
                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
                    </a>
                <?php } ?>
            </div>
        </div>
        <?php
        echo '</div>';
        echo '</div>';

        if ($subfolder_count > 0) {
            echo '<ul class="nav nav-pills flex-column bg-light">';
            display_folders($folder_id, $client_id, $indent + 1);
            echo '</ul>';
        }

        echo '</li>';
    }
}

// ---------------------------------------------
// DATA LOAD
// view=1 (thumbs) uses original files-only query
// view=0 (list) loads ALL files+documents, merges, sorts in PHP
// ---------------------------------------------

$items = [];
$num_rows = [0];

if ($view == 1) {

    // Thumbnail view - only image files, similar to original behavior
    $query_images = "AND (file_ext LIKE 'JPG' OR file_ext LIKE 'jpg' OR file_ext LIKE 'JPEG' OR file_ext LIKE 'jpeg' OR file_ext LIKE 'png' OR file_ext LIKE 'PNG' OR file_ext LIKE 'webp' OR file_ext LIKE 'WEBP')";

    if ($get_folder_id == 0 && isset($_GET["q"])) {
        $sql = mysqli_query(
            $mysqli,
            "SELECT SQL_CALC_FOUND_ROWS * FROM files
             LEFT JOIN users ON file_created_by = user_id
             WHERE file_client_id = $client_id
             AND file_archived_at IS NULL
             AND (file_name LIKE '%$q%' OR file_ext LIKE '%$q%' OR file_description LIKE '%$q%')
             $query_images
             ORDER BY file_name ASC
             LIMIT $record_from, $record_to"
        );
    } else {
        $sql = mysqli_query(
            $mysqli,
            "SELECT SQL_CALC_FOUND_ROWS * FROM files
             LEFT JOIN users ON file_created_by = user_id
             WHERE file_client_id = $client_id
             AND file_folder_id = $folder_id
             AND file_archived_at IS NULL
             AND (file_name LIKE '%$q%' OR file_ext LIKE '%$q%' OR file_description LIKE '%$q%')
             $query_images
             ORDER BY file_name ASC
             LIMIT $record_from, $record_to"
        );
    }

    $num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

} else {

    // -------- LIST VIEW: build unified items[] --------

    // Folder filter
    if ($get_folder_id == 0 && isset($_GET["q"])) {
        $file_folder_snippet = "";         // search across all folders
        $doc_folder_snippet  = "";
    } else {
        $file_folder_snippet = "AND file_folder_id = $folder_id";
        $doc_folder_snippet  = "AND document_folder_id = $folder_id";
    }

    // Search filters
    $safe_q = mysqli_real_escape_string($mysqli, $q);

    $file_search_snippet = "";
    if (!empty($q)) {
        $file_search_snippet = "AND (file_name LIKE '%$safe_q%' OR file_ext LIKE '%$safe_q%' OR file_description LIKE '%$safe_q%')";
    }

    $doc_search_snippet = "";
    if (!empty($q)) {
        $doc_search_snippet = "AND (MATCH(document_content_raw) AGAINST ('$safe_q') OR document_name LIKE '%$safe_q%')";
    }

    // Files query (NO limit - we'll paginate in PHP)
    $sql_files = mysqli_query(
        $mysqli,
        "SELECT files.*, users.user_name
         FROM files
         LEFT JOIN users ON file_created_by = user_id
         WHERE file_client_id = $client_id
         AND file_archived_at IS NULL
         $file_folder_snippet
         $file_search_snippet"
    );

    // Documents query (NO limit - paginate in PHP)
    $sql_documents = mysqli_query(
        $mysqli,
        "SELECT documents.*, users.user_name
         FROM documents
         LEFT JOIN users ON document_created_by = user_id
         WHERE document_client_id = $client_id
         AND document_archived_at IS NULL
         $doc_folder_snippet
         $doc_search_snippet"
    );

    // Normalize FILES into $items
    while ($row = mysqli_fetch_assoc($sql_files)) {
        $file_id            = intval($row['file_id']);
        $file_name          = nullable_htmlentities($row['file_name']);
        $file_description   = nullable_htmlentities($row['file_description']);
        $file_reference_name= nullable_htmlentities($row['file_reference_name']);
        $file_ext           = nullable_htmlentities($row['file_ext']);
        $file_size          = intval($row['file_size']);
        $file_mime_type     = nullable_htmlentities($row['file_mime_type']);
        $file_uploaded_by   = nullable_htmlentities($row['user_name']);
        $file_created_at    = nullable_htmlentities($row['file_created_at']);

        // determine icon
        if ($file_ext == 'pdf') {
            $file_icon = "file-pdf";
        } elseif (in_array($file_ext, ['gz','tar','zip','7z','rar'])) {
            $file_icon = "file-archive";
        } elseif (in_array($file_ext, ['txt','md'])) {
            $file_icon = "file-alt";
        } elseif ($file_ext == 'msg') {
            $file_icon = "envelope";
        } elseif (in_array($file_ext, ['doc','docx','odt'])) {
            $file_icon = "file-word";
        } elseif (in_array($file_ext, ['xls','xlsx','ods'])) {
            $file_icon = "file-excel";
        } elseif (in_array($file_ext, ['pptx','odp'])) {
            $file_icon = "file-powerpoint";
        } elseif (in_array($file_ext, ['mp3','wav','ogg'])) {
            $file_icon = "file-audio";
        } elseif (in_array($file_ext, ['mov','mp4','av1'])) {
            $file_icon = "file-video";
        } elseif (in_array($file_ext, ['jpg','jpeg','png','gif','webp','bmp','tif'])) {
            $file_icon = "file-image";
        } else {
            $file_icon = "file";
        }

        $items[] = [
            'kind'              => 'file',
            'id'                => $file_id,
            'name'              => $file_name,
            'description'       => $file_description,
            'reference_name'    => $file_reference_name,
            'icon'              => $file_icon,
            'mime'              => $file_mime_type,
            'size'              => $file_size,
            'created_at'        => $file_created_at,
            'created_by'        => $file_uploaded_by,
        ];
    }

    // Normalize DOCUMENTS into $items
    while ($row = mysqli_fetch_assoc($sql_documents)) {
        $document_id              = intval($row['document_id']);
        $document_name            = nullable_htmlentities($row['document_name']);
        $document_description     = nullable_htmlentities($row['document_description']);
        $document_created_by_name = nullable_htmlentities($row['user_name']);
        $document_created_at      = $row['document_created_at'];

        $items[] = [
            'kind'              => 'document',
            'id'                => $document_id,
            'name'              => $document_name,
            'description'       => $document_description,
            'mime'              => 'Document',
            'size'              => null,
            'created_at'        => $document_created_at,
            'created_by'        => $document_created_by_name,
        ];
    }

    // Sort combined items
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
    $order = isset($_GET['order']) ? $_GET['order'] : 'ASC';

    usort($items, function($a, $b) use ($sort, $order) {
        $direction = ($order === 'DESC') ? -1 : 1;

        if ($sort == 'created') {
            $valA = strtotime($a['created_at']);
            $valB = strtotime($b['created_at']);
        } elseif ($sort == 'type') {
            $valA = strtolower($a['mime']);
            $valB = strtolower($b['mime']);
        } elseif ($sort == 'size') {
            $valA = (int)($a['size'] ?? 0);
            $valB = (int)($b['size'] ?? 0);
        } else {
            // default: name
            $valA = strtolower($a['name']);
            $valB = strtolower($b['name']);
        }

        if ($valA == $valB) {
            return 0;
        }

        return ($valA < $valB) ? -1 * $direction : 1 * $direction;
    });

    // Total items (for pagination footer)
    $total_items = count($items);
    $num_rows = [$total_items];

    // Apply pagination slice
    $items = array_slice($items, $record_from, $record_to);
}

// ---------------------------------------------
// Root folder count (for "/" badge)
// ---------------------------------------------
$row_root_files = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('file_id') AS num FROM files WHERE file_folder_id = 0 AND file_client_id = $client_id AND file_archived_at IS NULL"));
$row_root_docs  = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('document_id') AS num FROM documents WHERE document_folder_id = 0 AND document_client_id = $client_id AND document_archived_at IS NULL"));
$num_root_items = intval($row_root_files['num']) + intval($row_root_docs['num']);

?>

<div class="card card-dark">

    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-folder mr-2"></i>Files</h3>

        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle" data-toggle="dropdown"><i class="fas fa-fw fa-plus mr-2"></i>New</button>
                <div class="dropdown-menu">
                    
                    
                    <a class="dropdown-item text-dark ajax-modal" href="#"
                       data-modal-url="modals/file/file_upload.php?client_id=<?= $client_id ?>&folder_id=<?= $get_folder_id ?>">
                        <i class="fas fa-fw fa-cloud-upload-alt mr-2"></i>Upload File
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-dark ajax-modal" href="#"
                       data-modal-url="modals/document/document_add.php?client_id=<?= $client_id ?>&folder_id=<?= $get_folder_id ?>">
                        <i class="fas fa-fw fa-file-alt mr-2"></i>Document
                    </a>
                    <a class="dropdown-item text-dark" href="#" data-toggle="modal"
                       data-target="#addDocumentFromTemplateModal">
                        <i class="fas fa-fw fa-file mr-2"></i>Document from Template
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-dark ajax-modal" href="#"
                       data-modal-url="modals/folder/folder_add.php?client_id=<?= $client_id ?>&current_folder_id=<?= $get_folder_id ?>">
                        <i class="fa fa-fw fa-folder-plus mr-2"></i>Folder
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <div class="row">

            <!-- Folders -->
            <div class="col-md-3 border-right mb-3">
                <h4>Folders</h4>
                <hr>
                <ul class="nav nav-pills flex-column bg-light">
                    <li class="nav-item">
                        <a class="nav-link <?php if ($get_folder_id == 0) { echo "active"; } ?>"
                           href="?client_id=<?php echo $client_id; ?>&folder_id=0">
                            / <?php if ($num_root_items > 0) { echo "<span class='badge badge-pill badge-dark float-right mt-1'>$num_root_items</span>"; } ?>
                        </a>
                    </li>
                    <?php
                    // Start folder tree from root
                    display_folders(0, $client_id);
                    ?>
                </ul>
            </div>

            <!-- Main content -->
            <div class="col-md-9">

                <!-- Search + view toggle -->
                <form autocomplete="off">
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                    <input type="hidden" name="view" value="<?php echo $view; ?>">
                    <input type="hidden" name="folder_id" value="<?php echo $get_folder_id; ?>">
                    <div class="row">
                        <div class="col-md-5">
                            <div class="input-group mb-3 mb-md-0">
                                <input type="search" class="form-control" name="q"
                                       value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>"
                                       placeholder="Search files and documents in <?php echo ($get_folder_id == 0 ? 'all folders' : 'current folder'); ?>">
                                <div class="input-group-append">
                                    <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="btn-group float-right">
                                <a href="?<?php echo $url_query_strings_sort; ?>&view=0&folder_id=<?php echo $get_folder_id; ?>" class="btn <?php if($view == 0){ echo "btn-primary"; } else { echo "btn-outline-secondary"; } ?>"><i class="fas fa-list-ul"></i></a>
                                <a href="?<?php echo $url_query_strings_sort; ?>&view=1&folder_id=<?php echo $get_folder_id; ?>" class="btn <?php if($view == 1){ echo "btn-primary"; } else { echo "btn-outline-secondary"; } ?>"><i class="fas fa-th-large"></i></a>

                                <div class="dropdown ml-2" id="bulkActionButton" hidden>
                                    <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                        <i class="fas fa-fw fa-layer-group mr-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#"
                                           data-modal-url="modals/file/file_bulk_move.php?client_id=<?= $client_id ?>"
                                           data-bulk="true">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Move Files
                                        </a>
                                        <a class="dropdown-item ajax-modal" href="#"
                                           data-modal-url="modals/document/document_bulk_move.php?client_id=<?= $client_id ?>"
                                           data-bulk="true">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Move Documents
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <button class="dropdown-item text-danger text-bold"
                                                type="submit" form="bulkActions" name="bulk_delete_files">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete Files
                                        </button>
                                        <button class="dropdown-item text-danger text-bold"
                                                type="submit" form="bulkActions" name="bulk_delete_documents">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete Documents
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </form>

                <!-- Breadcrumb -->
                <nav class="mt-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="?client_id=<?php echo $client_id; ?>&folder_id=0">
                                <i class="fas fa-fw fa-folder mr-2"></i>Root
                            </a>
                        </li>
                        <?php foreach ($folder_path as $folder) {
                            $bread_crumb_folder_id   = $folder['folder_id'];
                            $bread_crumb_folder_name = $folder['folder_name']; ?>
                            <li class="breadcrumb-item">
                                <a href="?client_id=<?php echo $client_id; ?>&folder_id=<?php echo $bread_crumb_folder_id; ?>">
                                    <i class="fas fa-fw fa-folder-open mr-2"></i><?php echo $bread_crumb_folder_name; ?>
                                </a>
                            </li>
                        <?php } ?>
                    </ol>
                </nav>

                <hr>

                <?php if ($view == 1) { ?>

                    <!-- THUMBNAIL VIEW (files only) -->
                    <div class="row">
                        <?php
                        $files = [];
                        while ($row = mysqli_fetch_array($sql)) {
                            $file_id            = intval($row['file_id']);
                            $file_name          = nullable_htmlentities($row['file_name']);
                            $file_reference_name= nullable_htmlentities($row['file_reference_name']);
                            $file_ext           = nullable_htmlentities($row['file_ext']);
                            $file_size          = intval($row['file_size']);
                            $file_size_KB       = number_format($file_size / 1024);
                            $file_mime_type     = nullable_htmlentities($row['file_mime_type']);
                            $file_uploaded_by   = nullable_htmlentities($row['user_name']);

                            $files[] = [
                                'id'      => $file_id,
                                'name'    => $file_name,
                                'preview' => "../uploads/clients/$client_id/$file_reference_name"
                            ];
                            ?>

                            <div class="col-xl-2 col-lg-2 col-md-6 col-sm-6 mb-3 text-center">

                                <a href="#" onclick="openModal(<?php echo count($files)-1; ?>)">
                                    <img class="img-thumbnail" src="<?php echo "../uploads/clients/$client_id/$file_reference_name"; ?>" alt="<?php echo $file_reference_name ?>">
                                </a>

                                <div>
                                    <div class="dropdown float-right">
                                        <button class="btn btn-link btn-sm" type="button" data-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item" href="<?php echo "../uploads/clients/$client_id/$file_reference_name"; ?>" download="<?php echo $file_name; ?>">
                                                <i class="fas fa-fw fa-cloud-download-alt mr-2"></i>Download
                                            </a>
                                            <a class="dropdown-item" href="#" data-toggle="modal" data-target="#shareModal" onclick="populateShareModal(<?php echo "$client_id, 'File', $file_id"; ?>)">
                                                <i class="fas fa-fw fa-share mr-2"></i>Share
                                            </a>
                                            <a class="dropdown-item ajax-modal" href="#"
                                               data-modal-url="modals/file/file_rename.php?id=<?= $file_id ?>">
                                                <i class="fas fa-fw fa-edit mr-2"></i>Rename
                                            </a>
                                            <a class="dropdown-item ajax-modal" href="#"
                                               data-modal-url="modals/file/file_move.php?id=<?= $file_id ?>">
                                                <i class="fas fa-fw fa-exchange-alt mr-2"></i>Move
                                            </a>
                                            <a class="dropdown-item" href="#" data-toggle="modal" data-target="#linkAssetToFileModal<?php echo $file_id; ?>">
                                                <i class="fas fa-fw fa-desktop mr-2"></i>Asset
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="post.php?archive_file=<?php echo $file_id; ?>">
                                                <i class="fas fa-fw fa-archive mr-2"></i>Archive
                                            </a>
                                            <?php if ($session_user_role == 3) { ?>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger text-bold" href="#" data-toggle="modal" data-target="#deleteFileModal" onclick="populateFileDeleteModal(<?php echo "$file_id , '$file_name'" ?>)">
                                                    <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                                </a>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <small class="text-secondary"><?php echo $file_name; ?></small>
                                </div>
                            </div>

                            <?php
                            require "modals/file/file_view.php";
                        }
                        ?>
                        <script>
                            var files = <?php echo json_encode($files); ?>;
                            var currentIndex = 0;
                        </script>
                    </div>

                <?php } else { ?>

                    <!-- LIST VIEW: unified Files + Documents -->
                    <form id="bulkActions" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                        <div class="table-responsive-sm">
                            <table class="table border">
                                <thead class="thead-light <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                                <tr>
                                    <td class="bg-light pr-0">
                                        <div class="form-check">
                                            <input class="form-check-input" id="selectAllCheckbox" type="checkbox" onclick="checkAll(this)">
                                        </div>
                                    </td>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=name&order=<?php echo $disp; ?>">
                                            Name <?php if ($sort == 'name') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=type&order=<?php echo $disp; ?>">
                                            Type <?php if ($sort == 'type') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=size&order=<?php echo $disp; ?>">
                                            Size <?php if ($sort == 'size') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=created&order=<?php echo $disp; ?>">
                                            Uploaded <?php if ($sort == 'created') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th></th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>

                                <tbody>
                                <?php
                                foreach ($items as $item) {

                                    if ($item['kind'] === 'file') {
                                        $file_id            = $item['id'];
                                        $file_name          = $item['name'];
                                        $file_description   = $item['description'];
                                        $file_reference_name= $item['reference_name'];
                                        $file_icon          = $item['icon'];
                                        $file_size          = $item['size'];
                                        $file_size_KB       = $file_size ? number_format($file_size / 1024) : 0;
                                        $file_mime_type     = $item['mime'];
                                        $file_uploaded_by   = $item['created_by'];
                                        $file_created_at    = $item['created_at'];

                                        // Shared?
                                        $sql_shared = mysqli_query(
                                            $mysqli,
                                            "SELECT * FROM shared_items
                                             WHERE item_client_id = $client_id
                                             AND item_active = 1
                                             AND item_views != item_view_limit
                                             AND item_expire_at > NOW()
                                             AND item_type = 'File'
                                             AND item_related_id = $file_id
                                             LIMIT 1"
                                        );
                                        $file_shared = (mysqli_num_rows($sql_shared) > 0);
                                        if ($file_shared) {
                                            $row_shared = mysqli_fetch_array($sql_shared);
                                            $item_recipient       = nullable_htmlentities($row_shared['item_recipient']);
                                            $item_expire_at_human = timeAgo($row_shared['item_expire_at']);
                                        }
                                        ?>
                                        <tr>
                                            <td class="bg-light pr-0">
                                                <div class="form-check">
                                                    <input class="form-check-input bulk-select" type="checkbox" name="file_ids[]" value="<?php echo $file_id ?>">
                                                </div>
                                            </td>
                                            <td>
                                                <a href="<?php echo "../uploads/clients/$client_id/$file_reference_name"; ?>" target="_blank">
                                                    <div class="media">
                                                        <i class="fa fa-fw fa-2x fa-<?php echo $file_icon; ?> text-dark mr-3"></i>
                                                        <div class="media-body">
                                                            <p>
                                                                <?php echo basename($file_name); ?>
                                                                <br>
                                                                <small class="text-secondary"><?php echo $file_description; ?></small>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </a>
                                            </td>
                                            <td><?php echo $file_mime_type; ?></td>
                                            <td><?php echo $file_size_KB; ?> KB</td>
                                            <td>
                                                <?php echo $file_created_at; ?>
                                                <div class="text-secondary mt-1"><?php echo $file_uploaded_by; ?></div>
                                            </td>
                                            <td>
                                                <?php if ($file_shared) { ?>
                                                    <div class="media" title="Expires <?php echo $item_expire_at_human; ?>">
                                                        <i class="fas fa-link mr-2 mt-1"></i>
                                                        <div class="media-body">Shared
                                                            <br>
                                                            <small class="text-secondary"><?php echo $item_recipient; ?></small>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <div class="dropdown dropleft text-center">
                                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-h"></i>
                                                    </button>
                                                    <div class="dropdown-menu">
                                                        <a class="dropdown-item" href="<?php echo "../uploads/clients/$client_id/$file_reference_name"; ?>" download="<?php echo $file_name; ?>">
                                                            <i class="fas fa-fw fa-cloud-download-alt mr-2"></i>Download
                                                        </a>
                                                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#shareModal" onclick="populateShareModal(<?php echo "$client_id, 'File', $file_id"; ?>)">
                                                            <i class="fas fa-fw fa-share mr-2"></i>Share
                                                        </a>
                                                        <a class="dropdown-item ajax-modal" href="#"
                                                           data-modal-url="modals/file/file_rename.php?id=<?= $file_id ?>">
                                                            <i class="fas fa-fw fa-edit mr-2"></i>Rename
                                                        </a>
                                                        <a class="dropdown-item ajax-modal" href="#"
                                                           data-modal-url="modals/file/file_move.php?id=<?= $file_id ?>">
                                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Move
                                                        </a>
                                                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#linkAssetToFileModal<?php echo $file_id; ?>">
                                                            <i class="fas fa-fw fa-desktop mr-2"></i>Asset
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_file=<?php echo $file_id; ?>">
                                                            <i class="fas fa-fw fa-archive mr-2"></i>Archive
                                                        </a>
                                                        <?php if ($session_user_role == 3) { ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item text-danger text-bold" href="#" data-toggle="modal" data-target="#deleteFileModal" onclick="populateFileDeleteModal(<?php echo "$file_id , '$file_name'" ?>)">
                                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                                            </a>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                        require "modals/file/file_link_asset.php";

                                    } else {
                                        // DOCUMENT ROW
                                        $document_id              = $item['id'];
                                        $document_name            = $item['name'];
                                        $document_description     = $item['description'];
                                        $document_created_by_name = $item['created_by'];
                                        $document_created_at      = date("m/d/Y", strtotime($item['created_at']));

                                        $sql_shared = mysqli_query(
                                            $mysqli,
                                            "SELECT * FROM shared_items
                                             WHERE item_client_id = $client_id
                                             AND item_active = 1
                                             AND item_views != item_view_limit
                                             AND item_expire_at > NOW()
                                             AND item_type = 'Document'
                                             AND item_related_id = $document_id
                                             LIMIT 1"
                                        );
                                        $doc_shared = (mysqli_num_rows($sql_shared) > 0);
                                        if ($doc_shared) {
                                            $row_shared = mysqli_fetch_array($sql_shared);
                                            $item_recipient       = nullable_htmlentities($row_shared['item_recipient']);
                                            $item_expire_at_human = timeAgo($row_shared['item_expire_at']);
                                        }
                                        ?>
                                        <tr>
                                            <td class="bg-light pr-0">
                                                <div class="form-check">
                                                    <input class="form-check-input bulk-select" type="checkbox" name="document_ids[]" value="<?php echo $document_id ?>">
                                                </div>
                                            </td>
                                            <td>
                                                <a href="document_details.php?client_id=<?php echo $client_id; ?>&document_id=<?php echo $document_id; ?>">
                                                    <div class="media">
                                                        <i class="fa fa-fw fa-2x fa-file-alt text-dark mr-3"></i>
                                                        <div class="media-body">
                                                            <p>
                                                                <?php echo $document_name; ?>
                                                                <br>
                                                                <small class="text-secondary"><?php echo $document_description; ?></small>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </a>
                                            </td>
                                            <td>Document</td>
                                            <td>-</td>
                                            <td>
                                                <?php echo $document_created_at; ?>
                                                <div class="text-secondary mt-1"><?php echo $document_created_by_name; ?></div>
                                            </td>
                                            <td>
                                                <?php if ($doc_shared) { ?>
                                                    <div class="media" title="Expires <?php echo $item_expire_at_human; ?>">
                                                        <i class="fas fa-link mr-2 mt-1"></i>
                                                        <div class="media-body">Shared
                                                            <br>
                                                            <small class="text-secondary"><?php echo $item_recipient; ?></small>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <div class="dropdown dropleft text-center">
                                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-h"></i>
                                                    </button>
                                                    <div class="dropdown-menu">
                                                        <a class="dropdown-item ajax-modal" href="#"
                                                           data-modal-size="lg"
                                                           data-modal-url="modals/document/document_view.php?id=<?= $document_id ?>">
                                                            <i class="fas fa-fw fa-eye mr-2"></i>Quick View
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item ajax-modal" href="#"
                                                           data-modal-size="lg"
                                                           data-modal-url="modals/document/document_edit.php?id=<?= $document_id ?>">
                                                            <i class="fas fa-fw fa-pencil-alt mr-2"></i>Edit
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#shareModal" onclick="populateShareModal(<?php echo "$client_id, 'Document', $document_id"; ?>)">
                                                            <i class="fas fa-fw fa-share mr-2"></i>Share
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item ajax-modal" href="#"
                                                           data-modal-url="modals/document/document_rename.php?id=<?= $document_id ?>">
                                                            <i class="fas fa-fw fa-pencil-alt mr-2"></i>Rename
                                                        </a>
                                                        <a class="dropdown-item ajax-modal" href="#"
                                                           data-modal-url="modals/document/document_move.php?id=<?= $document_id ?>">
                                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Move
                                                        </a>
                                                        <?php if ($session_user_role == 3) { ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item text-danger confirm-link" href="post.php?archive_document=<?php echo $document_id; ?>">
                                                                <i class="fas fa-fw fa-archive mr-2"></i>Archive
                                                            </a>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_document=<?php echo $document_id; ?>">
                                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                                            </a>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                <?php } ?>

                <?php require_once "../includes/filter_footer.php"; ?>

            </div>
        </div>
    </div>
</div>

<script>
function openModal(index) {
    currentIndex = index;
    updateModalContent();
    $('#viewFileModal').modal('show');
}

function updateModalContent() {
    document.getElementById('modalTitle').innerText = files[currentIndex].name;
    document.getElementById('modalImage').src = files[currentIndex].preview;
}

function nextFile() {
    currentIndex = (currentIndex + 1) % files.length;
    updateModalContent();
}

function prevFile() {
    currentIndex = (currentIndex - 1 + files.length) % files.length;
    updateModalContent();
}
</script>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "modals/share_modal.php";
require_once "modals/file/file_delete.php";
//require_once "modals/document/document_add_from_template.php";
require_once "../includes/footer.php";
