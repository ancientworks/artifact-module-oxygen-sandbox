<?php

use AncientWorks\Artifact\Artifact;
use AncientWorks\Artifact\ModuleProvider;
use AncientWorks\Artifact\Modules\OxygenSandbox\Sandbox;

defined('ABSPATH') || exit; ?>

<div class=" jp-dash-item">
  <div class="dops-card dops-section-header is-compact">
    <div class="dops-section-header__label">
      <span class="dops-section-header__label-text"> <b> <?= Sandbox::$module_name ?> </b> <code> <?= Sandbox::$module_version ?> </code> </span>
    </div>
    <div class="dops-section-header__actions" style="align-self: center;">
      <a href="<?= add_query_arg([
                  'page' => Artifact::$slug,
                  'route' => 'dashboard',
                  'module_id' => Sandbox::$module_id,
                  'action' => 'add',
                  '_wpnonce' => wp_create_nonce(Artifact::$slug)
                ], admin_url('admin.php')) ?>" class="sb-page-title-action">New Session</a>
      <a class="sb-page-title-action" id="import-session-btn">Import Session</a>
    </div>
  </div>

  <div class="jp-form-settings-group" id="upload-sandbox-session" style="display: none;">
    <div class="dops-card">
      <div class="upload-plugin-wrap">
        <div class="upload-plugin" style="display: block;">
          <p class="install-help">Import session by locating session file and clicking "Import".</p>
          <form method="post" enctype="multipart/form-data" class="wp-upload-form" action="<?= add_query_arg([
                                                                                              'page' => Artifact::$slug,
                                                                                              'route' => 'dashboard',
                                                                                              'module_id' => Sandbox::$module_id,
                                                                                              'action' => 'import',
                                                                                              '_wpnonce' => wp_create_nonce(Artifact::$slug)
                                                                                            ], admin_url('admin.php')) ?>">
            <input type="file" id="sessionfile" name="sessionfile" accept=".json" required="">
            <input type="submit" class="button" value="Import" disabled="">
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="jp-form-settings-group">
    <div class="dops-card">
      <div class="sb-sandbox-card" data-id="false">
        <input type="radio" id="default" name="sandbox" value="false" <?= ModuleProvider::$container[Sandbox::$module_id]->selected_session == false ? 'checked' : '' ?>>
        <div class="sb-card-content">
          <h2><label for="default">Disable</label><br></h2>
          <div class="sb-sandbox-description">No Sandbox session will be applied</div>
        </div>
      </div>
    </div>
  </div>

  <?php foreach (ModuleProvider::$container[Sandbox::$module_id]->get_sandbox_sessions()['sessions'] as $key => $value) : ?>
    <div class="jp-form-settings-group">
      <div class="dops-card">
        <div class="sb-sandbox-card" id="oxygen-sandbox-session-<?= $value['id'] ?>" style="position: relative;" data-id="<?= $value['id'] ?>">
          <input type="radio" name="sandbox" id="sandbox-<?= $value['id'] ?>" value="sandbox-<?= $value['id'] ?>" <?= ModuleProvider::$container[Sandbox::$module_id]->selected_session == $value['id'] ? 'checked' : '' ?>>
          <span style="position: absolute;right: 5px;top: 5px;opacity: 0.7;"><b>ID:</b> <?= $value['id'] ?></span>
          <div class="sb-card-content">
            <h2>
              <label for="sandbox-<?= $value['id'] ?>"><?= $value['name'] ?></label>
              <br>
            </h2>
            <div class="sb-sandbox-description-wrap">
              <input type="text" class="sb-sandbox-description" placeholder="Click here to change the SandBox session name">
            </div>

            <strong class="sb-preview-link">Preview link:</strong>
            <div class="sb-preview-link-form">
              <input type="text" onclick="this.select();" readonly value="<?= add_query_arg([
                                                                            Sandbox::$module_id => $value['secret'],
                                                                            'session'        => $value['id'],
                                                                          ], site_url()) ?>">
            </div>
            <div class="sb-actions">
              <a class="sb-sandbox-button sb-publish-button" href="<?= add_query_arg([
                                                                      'page' => Artifact::$slug,
                                                                      'route' => 'dashboard',
                                                                      'module_id' => Sandbox::$module_id,
                                                                      'action' => 'publish',
                                                                      'session' => $value['id'],
                                                                      '_wpnonce' => wp_create_nonce(Artifact::$slug)
                                                                    ], admin_url('admin.php')) ?>">
                <span class="dashicons dashicons-cloud-saved"></span> Publish
              </a>
              <a class="sb-sandbox-button sb-export-button" href="<?= add_query_arg([
                                                                    'page' => Artifact::$slug,
                                                                    'route' => 'dashboard',
                                                                    'module_id' => Sandbox::$module_id,
                                                                    'action' => 'export',
                                                                    'session' => $value['id'],
                                                                    '_wpnonce' => wp_create_nonce(Artifact::$slug)
                                                                  ], admin_url('admin.php')) ?>">
                <span class="dashicons dashicons-download"></span> Export
              </a>
              <a class="sb-sandbox-button sb-reset-button" href="<?= add_query_arg([
                                                                    'page' => Artifact::$slug,
                                                                    'route' => 'dashboard',
                                                                    'module_id' => Sandbox::$module_id,
                                                                    'action' => 'reset_secret',
                                                                    'session' => $value['id'],
                                                                    '_wpnonce' => wp_create_nonce(Artifact::$slug)
                                                                  ], admin_url('admin.php')) ?>">
                <span class="dashicons dashicons-update-alt"></span> Reset Link
              </a>
              <a class="sb-sandbox-button sb-delete-button" href="<?= add_query_arg([
                                                                    'page' => Artifact::$slug,
                                                                    'route' => 'dashboard',
                                                                    'module_id' => Sandbox::$module_id,
                                                                    'action' => 'delete',
                                                                    'session' => $value['id'],
                                                                    '_wpnonce' => wp_create_nonce(Artifact::$slug)
                                                                  ], admin_url('admin.php')) ?>">
                <span class="dashicons dashicons-trash"></span> Delete
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

</div>