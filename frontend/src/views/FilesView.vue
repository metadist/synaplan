<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-files-upload"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <!-- Storage Quota Widget -->
        <StorageQuotaWidget ref="storageWidget" @upgrade="handleUpgrade" />

        <!-- Compact Upload Bar -->
        <div
          class="surface-card p-5 relative"
          data-testid="section-upload-form"
          @dragenter.prevent="handleDragEnter"
          @dragover.prevent="handleDragOver"
          @dragleave="handleDragLeave"
          @drop.prevent="handleDrop"
        >
          <!-- Drag & Drop Overlay -->
          <Transition name="fade">
            <div
              v-if="isDragging"
              class="absolute inset-0 z-50 flex items-center justify-center bg-primary/10 dark:bg-primary/20 backdrop-blur-sm border-4 border-dashed border-primary rounded-lg pointer-events-none"
            >
              <div class="flex flex-col items-center gap-3 p-6 surface-card rounded-xl shadow-2xl">
                <div class="w-16 h-16 rounded-full bg-primary/20 flex items-center justify-center animate-bounce">
                  <Icon icon="mdi:cloud-upload" class="w-8 h-8 text-primary" />
                </div>
                <div class="text-center">
                  <p class="text-lg font-bold txt-primary mb-0.5">{{ $t('files.dropFiles') }}</p>
                  <p class="text-sm txt-secondary">{{ $t('files.dropFilesHint') }}</p>
                </div>
              </div>
            </div>
          </Transition>

          <input
            ref="fileInputRef"
            type="file"
            multiple
            accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.mp3,.mp4,.xlsx,.csv"
            class="hidden"
            data-testid="input-files"
            @change="handleFileSelect"
          />

          <!-- Selected Files List -->
          <div v-if="selectedFiles.length > 0" class="space-y-2 mb-4">
            <div
              v-for="(file, index) in selectedFiles"
              :key="index"
              class="flex items-center gap-3 p-3 rounded-lg border border-light-border/30 dark:border-dark-border/20 bg-black/[0.02] dark:bg-white/[0.02]"
            >
              <Icon :icon="getFileIcon(file.name)" class="w-5 h-5 txt-secondary" />
              <div class="flex-1 min-w-0">
                <p class="text-sm txt-primary truncate">{{ file.name }}</p>
                <p class="text-xs txt-secondary">{{ formatFileSize(file.size) }}</p>
              </div>
              <button
                :disabled="isUploading"
                class="p-1.5 rounded-lg hover:bg-red-500/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                :aria-label="$t('files.removeFile')"
                @click="removeSelectedFile(index)"
              >
                <XMarkIcon class="w-4 h-4 text-red-500" />
              </button>
            </div>
          </div>

          <!-- Action row: smart button + target breadcrumb -->
          <div class="flex items-center gap-4 flex-wrap">
            <!-- Smart Upload Button -->
            <button
              :disabled="isUploading"
              class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
              data-testid="btn-upload"
              @click="smartUploadAction"
            >
              <svg
                v-if="isUploading"
                class="animate-spin h-5 w-5"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <CloudArrowUpIcon v-else class="w-5 h-5" />
              {{ smartButtonLabel }}
            </button>

            <button
              v-if="selectedFiles.length > 0 && !isUploading"
              class="px-4 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5 transition-all text-sm flex items-center gap-1.5"
              data-testid="btn-add-more"
              @click="fileInputRef?.click()"
            >
              <Icon icon="heroicons:plus" class="w-4 h-4" />
              {{ $t('files.addMore') }}
            </button>

            <!-- Separator dot -->
            <span class="w-1 h-1 rounded-full bg-black/20 dark:bg-white/20 hidden sm:block"></span>

            <!-- Upload target breadcrumb -->
            <div class="flex items-center gap-2 text-xs">
              <Icon icon="heroicons:folder-solid" class="w-3.5 h-3.5 text-[var(--brand)]" />
              <span class="txt-secondary">{{ $t('files.target') }}:</span>
              <span class="font-semibold txt-primary">{{ activeUploadFolder || $t('files.rootFolder') }}</span>
              <button
                class="txt-secondary hover:text-[var(--brand)] transition-colors underline underline-offset-2 decoration-dotted"
                @click="folderPickerOpen = true"
              >
                {{ $t('files.changeTarget') }}
              </button>
            </div>

            <p class="text-xs txt-secondary ml-auto hidden sm:block">
              {{ $t('files.supportedFormats') }}
            </p>
          </div>

          <!-- Upload Progress Bar -->
          <Transition name="fade">
            <div v-if="isUploading && uploadProgress" class="mt-4" data-testid="upload-progress">
              <div class="flex items-center justify-between mb-1.5">
                <span class="text-sm txt-secondary">{{ $t('files.uploadProgress') }}</span>
                <span class="text-sm font-medium txt-primary">{{ uploadProgress.percentage }}%</span>
              </div>
              <div class="w-full h-2 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden">
                <div
                  class="h-full rounded-full bg-[var(--brand)] transition-all duration-300 ease-out"
                  :style="{ width: `${uploadProgress.percentage}%` }"
                ></div>
              </div>
              <p class="text-xs txt-secondary mt-1.5">
                {{
                  uploadProgress.percentage === 100
                    ? $t('files.processingFiles')
                    : $t('files.uploadingBytes', {
                        loaded: formatFileSize(uploadProgress.loaded),
                        total: formatFileSize(uploadProgress.total),
                      })
                }}
              </p>
            </div>
          </Transition>
        </div>

        <!-- Folder Picker Modal -->
        <Teleport to="body">
          <Transition name="dialog-fade">
            <div
              v-if="folderPickerOpen"
              class="fixed inset-0 z-50 flex items-center justify-center p-4"
              @click.self="folderPickerOpen = false"
            >
              <div class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"></div>
              <div class="relative surface-card rounded-2xl shadow-2xl max-w-md w-full p-6 animate-scale-in">
                <div class="flex items-center justify-between mb-5">
                  <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
                    <Icon icon="heroicons:folder" class="w-5 h-5 text-[var(--brand)]" />
                    {{ $t('files.folderPicker.title') }}
                  </h3>
                  <button
                    class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary transition-colors"
                    @click="folderPickerOpen = false"
                  >
                    <XMarkIcon class="w-5 h-5" />
                  </button>
                </div>

                <!-- Root / no folder option -->
                <button
                  class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border transition-all duration-150 mb-2"
                  :class="!selectedGroup && !groupKeyword
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10 text-[var(--brand)]'
                    : 'border-light-border/30 dark:border-dark-border/20 txt-secondary hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5'"
                  @click="clearFolderSelection(); folderPickerOpen = false"
                >
                  <Icon icon="heroicons:home" class="w-4 h-4 shrink-0" />
                  <span class="text-sm font-medium">{{ $t('files.rootFolder') }}</span>
                </button>

                <!-- Existing folders -->
                <div class="space-y-1.5 max-h-60 overflow-y-auto scroll-thin">
                  <button
                    v-for="folder in fileGroups"
                    :key="folder.name"
                    class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border transition-all duration-150"
                    :class="selectedGroup === folder.name && !groupKeyword
                      ? 'border-[var(--brand)] bg-[var(--brand)]/10 text-[var(--brand)]'
                      : 'border-light-border/30 dark:border-dark-border/20 txt-secondary hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5'"
                    @click="selectExistingFolder(folder.name); folderPickerOpen = false"
                  >
                    <Icon icon="heroicons:folder-solid" class="w-4 h-4 shrink-0" />
                    <span class="text-sm font-medium flex-1 text-left truncate">{{ folder.name }}</span>
                    <span class="text-[10px] font-semibold bg-black/5 dark:bg-white/10 px-2 py-0.5 rounded-full">{{ folder.count }}</span>
                  </button>
                </div>

                <!-- New folder input -->
                <div class="mt-3 pt-3 border-t border-light-border/20 dark:border-dark-border/10">
                  <div class="flex items-center gap-2">
                    <Icon icon="heroicons:folder-plus" class="w-4 h-4 text-[var(--brand)] shrink-0" />
                    <input
                      ref="newFolderInput"
                      v-model="groupKeyword"
                      type="text"
                      class="flex-1 px-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.03] txt-primary placeholder:txt-secondary/50 focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                      :placeholder="$t('files.folderPicker.newPlaceholder')"
                      data-testid="input-new-folder"
                      @keyup.enter="confirmNewFolder(); folderPickerOpen = false"
                    />
                    <button
                      :disabled="!groupKeyword.trim()"
                      class="px-3 py-2 rounded-lg btn-primary text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                      @click="confirmNewFolder(); folderPickerOpen = false"
                    >
                      {{ $t('common.save') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </Transition>
        </Teleport>

        <div class="surface-card p-6" data-testid="section-files-list">
          <!-- ====== ROOT VIEW: Folders + All Files ====== -->
          <template v-if="!openFolder">
            <h2 class="text-xl font-semibold txt-primary mb-6">{{ $t('files.yourFiles') }}</h2>

            <!-- Loading -->
            <div v-if="isLoading" class="flex items-center justify-center py-20" data-testid="state-loading">
              <svg class="animate-spin h-8 w-8 text-[var(--brand)]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </div>

            <!-- Empty: no folders AND no files -->
            <div
              v-else-if="fileGroups.length === 0 && paginatedFiles.length === 0"
              class="flex flex-col items-center justify-center py-20 gap-4"
              data-testid="state-empty"
            >
              <div class="w-20 h-20 rounded-2xl bg-[var(--brand)]/10 flex items-center justify-center">
                <Icon icon="heroicons:folder-plus" class="w-10 h-10 text-[var(--brand)]/40" />
              </div>
              <div class="text-center">
                <p class="text-base font-medium txt-primary mb-1">{{ $t('files.emptyState.title') }}</p>
                <p class="text-sm txt-secondary max-w-sm">{{ $t('files.emptyState.description') }}</p>
              </div>
            </div>

            <template v-else>
              <!-- Folder cards (with drag & drop) -->
              <div v-if="fileGroups.length > 0" class="mb-6" data-testid="section-folder-grid">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                  <button
                    v-for="folder in fileGroups"
                    :key="folder.name"
                    class="group/f flex flex-col items-center gap-3 p-5 rounded-2xl border transition-all duration-200 cursor-pointer"
                    :class="folderDropTarget === folder.name
                      ? 'border-[var(--brand)] bg-[var(--brand)]/10 shadow-lg shadow-[var(--brand)]/20 scale-[1.03]'
                      : 'border-light-border/20 dark:border-dark-border/15 hover:border-[var(--brand)]/30 hover:shadow-lg hover:shadow-[var(--brand)]/5 hover:bg-[var(--brand)]/[0.03]'"
                    :data-testid="`folder-card-${folder.name}`"
                    @click="enterFolder(folder.name)"
                    @dragenter.prevent="onFolderDragEnter(folder.name)"
                    @dragover.prevent
                    @dragleave="onFolderDragLeave(folder.name)"
                    @drop.prevent.stop="onFolderDrop($event, folder.name)"
                  >
                    <div class="relative">
                      <Icon
                        :icon="folderDropTarget === folder.name ? 'heroicons:folder-open-solid' : 'heroicons:folder-solid'"
                        class="w-12 h-12 transition-all duration-200"
                        :class="folderDropTarget === folder.name
                          ? 'text-[var(--brand)] scale-110'
                          : 'text-[var(--brand)]/50 group-hover/f:text-[var(--brand)] group-hover/f:scale-110'"
                      />
                      <span
                        class="absolute -top-1 -right-2.5 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-bold transition-all duration-200"
                        :class="folderDropTarget === folder.name
                          ? 'bg-[var(--brand)] text-white'
                          : 'bg-[var(--brand)]/15 text-[var(--brand)] group-hover/f:bg-[var(--brand)] group-hover/f:text-white'"
                      >
                        {{ folder.count }}
                      </span>
                    </div>
                    <span
                      class="text-xs font-medium truncate max-w-full text-center transition-colors"
                      :class="folderDropTarget === folder.name ? 'text-[var(--brand)]' : 'txt-primary group-hover/f:text-[var(--brand)]'"
                    >
                      {{ folder.name }}
                    </span>
                  </button>
                </div>
              </div>

              <!-- Bulk actions -->
              <div v-if="selectedFileIds.length > 0" class="mb-4">
                <button
                  class="px-4 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors flex items-center gap-2 text-sm"
                  data-testid="btn-delete-selected"
                  @click="deleteSelected"
                >
                  <TrashIcon class="w-4 h-4" />
                  {{ $t('files.deleteSelected') }} ({{ selectedFileIds.length }})
                </button>
              </div>

              <!-- All files table -->
              <div v-if="paginatedFiles.length > 0" data-testid="section-table">
                <div v-if="fileGroups.length > 0" class="flex items-center gap-2 mb-3 pt-2 border-t border-light-border/10 dark:border-dark-border/10">
                  <Icon icon="heroicons:document-text" class="w-4 h-4 txt-secondary" />
                  <span class="text-xs font-medium txt-secondary uppercase tracking-wider">{{ $t('files.allFiles') }}</span>
                  <span class="text-xs txt-secondary">({{ totalCount }})</span>
                </div>
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                      <th class="text-left py-2.5 px-2 w-8">
                        <input type="checkbox" :checked="allSelected" class="checkbox-brand" @change="toggleSelectAll" />
                      </th>
                      <th class="text-left py-2.5 px-3 txt-secondary text-xs font-medium">{{ $t('files.name') }}</th>
                      <th class="text-left py-2.5 px-3 txt-secondary text-xs font-medium w-24">{{ $t('files.size') }}</th>
                      <th class="text-left py-2.5 px-3 txt-secondary text-xs font-medium w-28">{{ $t('files.uploaded') }}</th>
                      <th class="w-36"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="file in paginatedFiles"
                      :key="file.id"
                      class="group border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors"
                      data-testid="item-file"
                    >
                      <td class="py-2.5 px-2">
                        <input type="checkbox" :checked="selectedFileIds.includes(file.id)" class="checkbox-brand" @change="toggleFileSelection(file.id)" />
                      </td>
                      <td class="py-2.5 px-3">
                        <div class="flex items-center gap-3 min-w-0">
                          <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" :class="getFileColorClass(file.filename)">
                            <Icon :icon="getFileIcon(file.filename)" class="w-4 h-4" />
                          </div>
                          <div class="flex flex-col gap-0.5 min-w-0">
                            <span class="text-sm txt-primary truncate">{{ file.filename }}</span>
                            <button
                              v-if="file.group_key"
                              class="inline-flex items-center gap-1 self-start text-[10px] text-[var(--brand)]/70 hover:text-[var(--brand)] transition-colors"
                              @click="enterFolder(file.group_key!)"
                            >
                              <Icon icon="heroicons:folder-solid" class="w-3 h-3" />
                              {{ file.group_key }}
                            </button>
                          </div>
                        </div>
                      </td>
                      <td class="py-2.5 px-3 txt-secondary text-xs whitespace-nowrap">{{ formatFileSize(file.file_size) }}</td>
                      <td class="py-2.5 px-3 txt-secondary text-xs whitespace-nowrap">{{ file.uploaded_date }}</td>
                      <td class="py-2.5 px-3">
                        <div class="flex gap-0.5 justify-end opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                          <div class="relative">
                            <button
                              class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:text-[var(--brand)] transition-colors"
                              :title="$t('files.moveTo')"
                              @click.stop="toggleFolderMenu(file.id)"
                            >
                              <Icon icon="heroicons:folder-arrow-down" class="w-4 h-4" />
                            </button>
                            <Transition name="fade">
                              <div
                                v-if="folderMenuOpen === file.id"
                                class="absolute right-0 top-full mt-1 z-30 w-52 surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-xl py-1.5 overflow-hidden"
                              >
                                <div class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider txt-secondary">{{ $t('files.moveTo') }}</div>
                                <button
                                  v-for="folder in fileGroups"
                                  :key="folder.name"
                                  class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
                                  @click="moveFileToFolder(file.id, folder.name)"
                                >
                                  <Icon icon="heroicons:folder-solid" class="w-4 h-4 shrink-0" />
                                  <span class="truncate">{{ folder.name }}</span>
                                </button>
                                <div class="border-t border-light-border/20 dark:border-dark-border/10 mt-1.5 pt-1.5">
                                  <div class="flex items-center gap-1.5 px-3 py-1">
                                    <Icon icon="heroicons:folder-plus" class="w-4 h-4 text-[var(--brand)] shrink-0" />
                                    <input
                                      v-model="newMoveTarget"
                                      type="text"
                                      class="flex-1 text-xs bg-transparent txt-primary placeholder:txt-secondary/50 focus:outline-none"
                                      :placeholder="$t('files.folderPicker.newPlaceholder')"
                                      @keyup.enter="moveFileToFolder(file.id, newMoveTarget.trim())"
                                      @click.stop
                                    />
                                  </div>
                                </div>
                              </div>
                            </Transition>
                          </div>
                          <button class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors" :title="$t('files.download')" @click="downloadFile(file.id, file.filename)">
                            <ArrowDownTrayIcon class="w-4 h-4" />
                          </button>
                          <button class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors" :title="$t('common.view')" @click="viewFileContent(file.id)">
                            <Icon icon="heroicons:eye" class="w-4 h-4" />
                          </button>
                          <button class="p-1.5 rounded-lg hover:bg-red-500/10 text-red-400/70 hover:text-red-500 transition-colors" :title="$t('files.delete')" @click="deleteFile(file.id)">
                            <TrashIcon class="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-light-border/10 dark:border-dark-border/10">
                  <span class="text-xs txt-secondary">{{ $t('files.page') }} {{ currentPage }} / {{ totalPages }}</span>
                  <div class="flex gap-2">
                    <button :disabled="currentPage === 1" class="px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed" @click="previousPage">{{ $t('files.previous') }}</button>
                    <button :disabled="currentPage >= totalPages" class="px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed" @click="nextPage">{{ $t('files.next') }}</button>
                  </div>
                </div>
              </div>
            </template>
          </template>

          <!-- ====== FOLDER VIEW: Files inside a folder ====== -->
          <template v-else>
            <!-- Breadcrumb header -->
            <div class="flex items-center gap-3 mb-6">
              <button
                class="p-2 rounded-xl hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors"
                data-testid="btn-back-to-root"
                @click="exitFolder"
              >
                <Icon icon="heroicons:arrow-left" class="w-5 h-5" />
              </button>
              <div class="flex items-center gap-2.5 min-w-0">
                <button
                  class="text-sm txt-secondary hover:txt-primary hover:underline transition-colors shrink-0"
                  @click="exitFolder"
                >
                  {{ $t('files.yourFiles') }}
                </button>
                <Icon icon="heroicons:chevron-right" class="w-3.5 h-3.5 txt-secondary/30 shrink-0" />
                <Icon icon="heroicons:folder-open-solid" class="w-5 h-5 text-[var(--brand)] shrink-0" />
                <h2 class="text-lg font-semibold txt-primary truncate">{{ openFolder }}</h2>
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-[var(--brand)]/10 text-[var(--brand)]"
                >
                  {{ totalCount }} {{ $t('files.files') }}
                </span>
              </div>
            </div>

            <!-- Bulk actions -->
            <div v-if="selectedFileIds.length > 0" class="mb-4 flex items-center gap-3">
              <button
                class="px-4 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors flex items-center gap-2 text-sm"
                data-testid="btn-delete-selected"
                @click="deleteSelected"
              >
                <TrashIcon class="w-4 h-4" />
                {{ $t('files.deleteSelected') }} ({{ selectedFileIds.length }})
              </button>
            </div>

            <!-- Loading inside folder -->
            <div v-if="isLoading" class="flex items-center justify-center py-20" data-testid="state-loading-folder">
              <svg class="animate-spin h-8 w-8 text-[var(--brand)]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </div>

            <!-- Empty folder -->
            <div
              v-else-if="paginatedFiles.length === 0"
              class="flex flex-col items-center justify-center py-20 gap-4"
              data-testid="state-empty-folder"
            >
              <div class="w-16 h-16 rounded-2xl bg-[var(--brand)]/10 flex items-center justify-center">
                <Icon icon="heroicons:folder-open" class="w-8 h-8 text-[var(--brand)]/40" />
              </div>
              <p class="text-sm txt-secondary">{{ $t('files.emptyState.emptyFolder') }}</p>
            </div>

            <!-- Finder-style file table -->
            <div v-else data-testid="section-table">
              <table class="w-full">
                <thead>
                  <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                    <th class="text-left py-2.5 px-2 w-8">
                      <input type="checkbox" :checked="allSelected" class="checkbox-brand" @change="toggleSelectAll" />
                    </th>
                    <th class="text-left py-2.5 px-3 txt-secondary text-xs font-medium">{{ $t('files.name') }}</th>
                    <th class="text-left py-2.5 px-3 txt-secondary text-xs font-medium w-24">{{ $t('files.size') }}</th>
                    <th class="text-left py-2.5 px-3 txt-secondary text-xs font-medium w-28">{{ $t('files.uploaded') }}</th>
                    <th class="w-32"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="file in paginatedFiles"
                    :key="file.id"
                    class="group border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors"
                    data-testid="item-file"
                  >
                    <td class="py-2.5 px-2">
                      <input
                        type="checkbox"
                        :checked="selectedFileIds.includes(file.id)"
                        class="checkbox-brand"
                        @change="toggleFileSelection(file.id)"
                      />
                    </td>
                    <td class="py-2.5 px-3">
                      <div class="flex items-center gap-3 min-w-0">
                        <div
                          class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                          :class="getFileColorClass(file.filename)"
                        >
                          <Icon :icon="getFileIcon(file.filename)" class="w-4 h-4" />
                        </div>
                        <span class="text-sm txt-primary truncate">{{ file.filename }}</span>
                      </div>
                    </td>
                    <td class="py-2.5 px-3 txt-secondary text-xs whitespace-nowrap">
                      {{ formatFileSize(file.file_size) }}
                    </td>
                    <td class="py-2.5 px-3 txt-secondary text-xs whitespace-nowrap">
                      {{ file.uploaded_date }}
                    </td>
                    <td class="py-2.5 px-3">
                      <div class="flex gap-0.5 justify-end opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                        <!-- Move to another folder -->
                        <div class="relative">
                          <button
                            class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:text-[var(--brand)] transition-colors"
                            :title="$t('files.moveTo')"
                            @click.stop="toggleFolderMenu(file.id)"
                          >
                            <Icon icon="heroicons:folder-arrow-down" class="w-4 h-4" />
                          </button>
                          <Transition name="fade">
                            <div
                              v-if="folderMenuOpen === file.id"
                              class="absolute right-0 top-full mt-1 z-30 w-52 surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-xl py-1.5 overflow-hidden"
                            >
                              <div class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider txt-secondary">
                                {{ $t('files.moveTo') }}
                              </div>
                              <button
                                v-for="folder in fileGroups"
                                :key="folder.name"
                                class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
                                :class="{ 'text-[var(--brand)] font-medium': folder.name === openFolder }"
                                @click="moveFileToFolder(file.id, folder.name)"
                              >
                                <Icon
                                  :icon="folder.name === openFolder ? 'heroicons:folder-open-solid' : 'heroicons:folder-solid'"
                                  class="w-4 h-4 shrink-0"
                                />
                                <span class="truncate">{{ folder.name }}</span>
                                <Icon v-if="folder.name === openFolder" icon="heroicons:check" class="w-3.5 h-3.5 ml-auto text-[var(--brand)]" />
                              </button>
                              <div class="border-t border-light-border/20 dark:border-dark-border/10 mt-1.5 pt-1.5">
                                <div class="flex items-center gap-1.5 px-3 py-1">
                                  <Icon icon="heroicons:folder-plus" class="w-4 h-4 text-[var(--brand)] shrink-0" />
                                  <input
                                    v-model="newMoveTarget"
                                    type="text"
                                    class="flex-1 text-xs bg-transparent txt-primary placeholder:txt-secondary/50 focus:outline-none"
                                    :placeholder="$t('files.folderPicker.newPlaceholder')"
                                    @keyup.enter="moveFileToFolder(file.id, newMoveTarget.trim())"
                                    @click.stop
                                  />
                                </div>
                              </div>
                            </div>
                          </Transition>
                        </div>
                        <button
                          class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors"
                          :title="$t('files.download')"
                          data-testid="btn-download"
                          @click="downloadFile(file.id, file.filename)"
                        >
                          <ArrowDownTrayIcon class="w-4 h-4" />
                        </button>
                        <button
                          class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors"
                          :title="$t('common.view')"
                          data-testid="btn-view"
                          @click="viewFileContent(file.id)"
                        >
                          <Icon icon="heroicons:eye" class="w-4 h-4" />
                        </button>
                        <button
                          class="p-1.5 rounded-lg hover:bg-red-500/10 text-red-400/70 hover:text-red-500 transition-colors"
                          :title="$t('files.delete')"
                          data-testid="btn-delete"
                          @click="deleteFile(file.id)"
                        >
                          <TrashIcon class="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>

              <!-- Pagination -->
              <div
                v-if="totalPages > 1"
                class="flex items-center justify-between mt-4 pt-4 border-t border-light-border/10 dark:border-dark-border/10"
                data-testid="section-pagination"
              >
                <span class="text-xs txt-secondary">
                  {{ $t('files.page') }} {{ currentPage }} / {{ totalPages }}
                </span>
                <div class="flex gap-2">
                  <button
                    :disabled="currentPage === 1"
                    class="px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    @click="previousPage"
                  >
                    {{ $t('files.previous') }}
                  </button>
                  <button
                    :disabled="currentPage >= totalPages"
                    class="px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    @click="nextPage"
                  >
                    {{ $t('files.next') }}
                  </button>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- File Content Modal -->
    <FileContentModal :is-open="isModalOpen" :file-id="selectedFileId" @close="closeModal" />
    <ShareModal
      :is-open="isShareModalOpen"
      :file-id="shareFileId"
      :filename="shareFileName"
      @close="closeShareModal"
      @shared="handleShared"
      @unshared="handleUnshared"
    />

    <!-- Confirm Delete Dialog (Single File) -->
    <ConfirmDialog
      :is-open="isConfirmOpen"
      title="Delete File"
      message="Are you sure you want to delete this file? This action cannot be undone."
      confirm-text="Delete"
      cancel-text="Cancel"
      variant="danger"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />

    <!-- Confirm Delete Selected Dialog (Multiple Files) -->
    <Teleport to="body">
      <Transition name="dialog-fade">
        <div
          v-if="isDeleteSelectedOpen"
          class="fixed inset-0 z-50 flex items-center justify-center p-4"
          data-testid="modal-delete-selected-root"
          @click.self="cancelDeleteSelected"
        >
          <!-- Backdrop -->
          <div
            class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"
            data-testid="modal-delete-selected-backdrop"
          ></div>

          <!-- Dialog -->
          <div
            class="relative surface-card rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4 animate-scale-in"
            role="dialog"
            aria-modal="true"
            data-testid="modal-delete-selected"
          >
            <!-- Icon and Title -->
            <div class="flex items-center gap-3">
              <div
                class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center bg-red-500/10 text-red-500"
              >
                <ExclamationTriangleIcon class="w-6 h-6" />
              </div>
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('files.deleteSelectedConfirmTitle') }}
              </h3>
            </div>

            <!-- Message -->
            <p class="txt-secondary text-sm leading-relaxed">
              {{ $t('files.deleteSelectedConfirmMessage', { count: selectedFileIds.length }) }}
            </p>

            <!-- Actions -->
            <div class="flex gap-3 justify-end pt-2">
              <button
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-all text-sm font-medium"
                data-testid="btn-delete-selected-cancel"
                @click="cancelDeleteSelected"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                class="px-4 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white text-sm font-medium transition-all"
                data-testid="btn-delete-selected-confirm"
                @click="confirmDeleteSelected"
              >
                {{ $t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import FileContentModal from '@/components/FileContentModal.vue'
import ShareModal from '@/components/ShareModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import StorageQuotaWidget from '@/components/StorageQuotaWidget.vue'
import { Icon } from '@iconify/vue'
import {
  CloudArrowUpIcon,
  TrashIcon,
  ArrowDownTrayIcon,
  XMarkIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import filesService, { type FileItem, type UploadProgress } from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'
import { useFilePersistence } from '@/composables/useInputPersistence'

const { t } = useI18n()
const { success: showSuccess, error: showError, info: showInfo } = useNotification()

// File persistence - save selected files metadata
const { saveFileMetadata, loadFileMetadata, clearFiles } = useFilePersistence('files_upload')

const storageWidget = ref<InstanceType<typeof StorageQuotaWidget> | null>(null)

const groupKeyword = ref('')
const selectedGroup = ref('')
const isCreatingNewFolder = ref(false)
const newFolderInput = ref<HTMLInputElement | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
// File upload state (removed processLevel - always vectorize)
const selectedFiles = ref<File[]>([])
const filterGroup = ref('')
const openFolder = ref<string | null>(null)
const folderMenuOpen = ref<number | null>(null)
const newMoveTarget = ref('')
const files = ref<FileItem[]>([])
const fileGroups = ref<Array<{ name: string; count: number }>>([])
const selectedFileIds = ref<number[]>([])
const currentPage = ref(1)
const itemsPerPage = 10
const isUploading = ref(false)
const uploadProgress = ref<UploadProgress | null>(null)
const isLoading = ref(false)

// Drag & Drop state
const isDragging = ref(false)
const dragCounter = ref(0)
const folderDropTarget = ref<string | null>(null)

// Folder picker modal
const folderPickerOpen = ref(false)

// Modal state
const isModalOpen = ref(false)
const selectedFileId = ref<number | null>(null)

// Share modal state
const isShareModalOpen = ref(false)
const shareFileId = ref<number | null>(null)
const shareFileName = ref('')

// Confirm dialog state
const isConfirmOpen = ref(false)
const fileToDelete = ref<number | null>(null)
const isDeleteSelectedOpen = ref(false)
const totalCount = ref(0)

const totalPages = computed(() => {
  return Math.ceil(totalCount.value / itemsPerPage)
})

const paginatedFiles = computed(() => files.value)

const allSelected = computed(() => {
  return (
    paginatedFiles.value.length > 0 &&
    paginatedFiles.value.every((file) => selectedFileIds.value.includes(file.id))
  )
})

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    const newFiles = Array.from(target.files)
    const existingNames = new Set(selectedFiles.value.map((f) => f.name + f.size))
    const unique = newFiles.filter((f) => !existingNames.has(f.name + f.size))
    selectedFiles.value = [...selectedFiles.value, ...unique]
    saveFileMetadata(selectedFiles.value)
    target.value = ''
  }
}

const smartButtonLabel = computed(() => {
  if (isUploading.value) return t('files.uploading')
  if (selectedFiles.value.length > 0) {
    return t('files.uploadCount', { count: selectedFiles.value.length })
  }
  return t('files.selectAndUpload')
})

const smartUploadAction = () => {
  if (isUploading.value) return
  if (selectedFiles.value.length > 0) {
    uploadFiles()
  } else {
    fileInputRef.value?.click()
  }
}

const removeSelectedFile = (index: number) => {
  if (isUploading.value) {
    return
  }
  selectedFiles.value.splice(index, 1)
}

// Folder picker helpers — auto-target the open folder
const activeUploadFolder = computed(() => selectedGroup.value || groupKeyword.value || openFolder.value || '')

const selectExistingFolder = (name: string) => {
  if (selectedGroup.value === name) {
    selectedGroup.value = ''
  } else {
    selectedGroup.value = name
    groupKeyword.value = ''
    isCreatingNewFolder.value = false
  }
}

const confirmNewFolder = () => {
  if (groupKeyword.value.trim()) {
    selectedGroup.value = groupKeyword.value.trim()
    isCreatingNewFolder.value = false
  }
}

const clearFolderSelection = () => {
  selectedGroup.value = ''
  groupKeyword.value = ''
  isCreatingNewFolder.value = false
}

// Folder navigation (file list)
const enterFolder = (name: string) => {
  openFolder.value = name
  filterGroup.value = name
  currentPage.value = 1
  loadFiles(1)
}

const exitFolder = () => {
  openFolder.value = null
  filterGroup.value = ''
  currentPage.value = 1
  loadFiles(1)
}

// Folder move menu
const toggleFolderMenu = (fileId: number) => {
  if (folderMenuOpen.value === fileId) {
    folderMenuOpen.value = null
    newMoveTarget.value = ''
  } else {
    folderMenuOpen.value = fileId
    newMoveTarget.value = ''
  }
}

const moveFileToFolder = async (fileId: number, folderName: string) => {
  if (!folderName.trim()) return
  folderMenuOpen.value = null
  newMoveTarget.value = ''

  try {
    await filesService.updateFileGroupKey(fileId, folderName.trim())
    showSuccess(t('files.movedSuccess', { folder: folderName.trim() }))
    await loadFileGroups()
    await loadFiles()
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : 'Failed to move file'
    showError(msg)
  }
}

const closeFolderMenu = (e: MouseEvent) => {
  if (folderMenuOpen.value !== null) {
    const target = e.target as HTMLElement
    if (!target.closest('[data-testid="section-table"]')) {
      folderMenuOpen.value = null
      newMoveTarget.value = ''
    }
  }
}

const getFileColorClass = (filename: string): string => {
  const ext = filename.split('.').pop()?.toLowerCase() || ''
  const colorMap: Record<string, string> = {
    pdf: 'bg-red-500/10 text-red-500',
    docx: 'bg-blue-500/10 text-blue-500',
    doc: 'bg-blue-500/10 text-blue-500',
    txt: 'bg-gray-500/10 text-gray-500',
    jpg: 'bg-purple-500/10 text-purple-500',
    jpeg: 'bg-purple-500/10 text-purple-500',
    png: 'bg-purple-500/10 text-purple-500',
    mp3: 'bg-pink-500/10 text-pink-500',
    mp4: 'bg-pink-500/10 text-pink-500',
    xlsx: 'bg-emerald-500/10 text-emerald-500',
    csv: 'bg-emerald-500/10 text-emerald-500',
  }
  return colorMap[ext] || 'bg-[var(--brand)]/10 text-[var(--brand)]'
}

// Drag & Drop handlers
const handleDragEnter = (event: DragEvent) => {
  // Check if dragging files
  if (event.dataTransfer?.types.includes('Files')) {
    dragCounter.value++
    isDragging.value = true
  }
}

const handleDragOver = (event: DragEvent) => {
  // Just prevent default to allow drop, don't change state here
  event.preventDefault()
}

const handleDragLeave = (_event: DragEvent) => {
  dragCounter.value--
  // Only hide overlay when truly leaving the area
  if (dragCounter.value <= 0) {
    dragCounter.value = 0
    isDragging.value = false
  }
}

const handleDrop = async (event: DragEvent) => {
  dragCounter.value = 0
  isDragging.value = false

  const droppedFiles = event.dataTransfer?.files
  if (droppedFiles && droppedFiles.length > 0) {
    selectedFiles.value = [...selectedFiles.value, ...Array.from(droppedFiles)]
    showSuccess(t('files.filesAddedToQueue', { count: droppedFiles.length }))
    saveFileMetadata(selectedFiles.value)
  }
}

// Folder card drag & drop — upload directly into a folder
const onFolderDragEnter = (folderName: string) => {
  folderDropTarget.value = folderName
}

const onFolderDragLeave = (folderName: string) => {
  if (folderDropTarget.value === folderName) {
    folderDropTarget.value = null
  }
}

const onFolderDrop = async (event: DragEvent, folderName: string) => {
  folderDropTarget.value = null
  isDragging.value = false
  dragCounter.value = 0

  const droppedFiles = event.dataTransfer?.files
  if (!droppedFiles || droppedFiles.length === 0) return

  isUploading.value = true
  uploadProgress.value = { loaded: 0, total: 0, percentage: 0 }

  try {
    const result = await filesService.uploadFiles({
      files: Array.from(droppedFiles),
      groupKey: folderName,
      processLevel: 'vectorize',
      onProgress: (progress) => { uploadProgress.value = progress },
    })

    if (result.success) {
      showSuccess(t('files.droppedToFolder', { count: result.files.length, folder: folderName }))
      await loadFileGroups()
      await loadFiles()
      if (storageWidget.value) await storageWidget.value.refresh()
    } else {
      result.errors.forEach((err) => showError(`${err.filename}: ${err.error}`))
    }
  } catch (err) {
    console.error('Upload error:', err)
    showError('Failed to upload files: ' + (err as Error).message)
  } finally {
    isUploading.value = false
    uploadProgress.value = null
  }
}

const getFileIcon = (filename: string): string => {
  const ext = filename.split('.').pop()?.toLowerCase() || ''

  const iconMap: Record<string, string> = {
    pdf: 'heroicons:document-text',
    docx: 'heroicons:document-text',
    doc: 'heroicons:document-text',
    txt: 'heroicons:document-text',
    jpg: 'heroicons:photo',
    jpeg: 'heroicons:photo',
    png: 'heroicons:photo',
    gif: 'heroicons:photo',
    webp: 'heroicons:photo',
    mp3: 'heroicons:musical-note',
    mp4: 'heroicons:film',
    xlsx: 'heroicons:table-cells',
    csv: 'heroicons:table-cells',
  }

  return iconMap[ext] || 'heroicons:document'
}

const uploadFiles = async () => {
  if (selectedFiles.value.length === 0) {
    showError('Please select files to upload')
    return
  }

  const groupKey = activeUploadFolder.value

  isUploading.value = true
  uploadProgress.value = { loaded: 0, total: 0, percentage: 0 }

  try {
    const result = await filesService.uploadFiles({
      files: selectedFiles.value,
      groupKey,
      processLevel: 'vectorize', // Always vectorize for optimal RAG performance
      onProgress: (progress) => {
        uploadProgress.value = progress
      },
    })

    if (result.success) {
      showSuccess(`Successfully uploaded ${result.files.length} file(s)`)

      selectedFiles.value = []
      if (!openFolder.value) {
        groupKeyword.value = ''
        selectedGroup.value = ''
      }
      isCreatingNewFolder.value = false
      if (fileInputRef.value) fileInputRef.value.value = ''
      clearFiles()

      await loadFileGroups()
      await loadFiles()
      if (storageWidget.value) {
        await storageWidget.value.refresh()
      }
    } else {
      // Show errors
      result.errors.forEach((error) => {
        showError(`${error.filename}: ${error.error}`)
      })
    }
  } catch (error) {
    console.error('Upload error:', error)
    showError('Failed to upload files: ' + (error as Error).message)
  } finally {
    isUploading.value = false
    uploadProgress.value = null
  }
}

const handleUpgrade = () => {
  // Navigate to pricing/subscription page
  showInfo('Upgrade functionality coming soon! Contact support@synaplan.com for premium plans.')
}

const loadFiles = async (page = currentPage.value) => {
  isLoading.value = true

  try {
    const response = await filesService.listFiles(
      filterGroup.value || undefined,
      page,
      itemsPerPage
    )

    files.value = response.files
    totalCount.value = response.pagination.total
    currentPage.value = response.pagination.page
  } catch (error: any) {
    console.error('Failed to load files:', error)

    // Handle 401 (not authenticated) gracefully
    if (error.message && error.message.includes('401')) {
      // Silently fail - router should redirect to login
      files.value = []
      totalCount.value = 0
    } else {
      showError('Failed to load files')
    }
  } finally {
    isLoading.value = false
  }
}

const loadFileGroups = async () => {
  try {
    fileGroups.value = await filesService.getFileGroups()
  } catch (error: any) {
    console.error('Failed to load file groups:', error)

    // Handle 401 (not authenticated) gracefully
    if (error.message && error.message.includes('401')) {
      // Silently fail - router should redirect to login
      fileGroups.value = []
    }
  }
}

const toggleFileSelection = (fileId: number) => {
  const index = selectedFileIds.value.indexOf(fileId)
  if (index > -1) {
    selectedFileIds.value.splice(index, 1)
  } else {
    selectedFileIds.value.push(fileId)
  }
}

const toggleSelectAll = () => {
  if (allSelected.value) {
    paginatedFiles.value.forEach((file) => {
      const index = selectedFileIds.value.indexOf(file.id)
      if (index > -1) {
        selectedFileIds.value.splice(index, 1)
      }
    })
  } else {
    paginatedFiles.value.forEach((file) => {
      if (!selectedFileIds.value.includes(file.id)) {
        selectedFileIds.value.push(file.id)
      }
    })
  }
}

const deleteSelected = () => {
  if (selectedFileIds.value.length === 0) return
  isDeleteSelectedOpen.value = true
}

const confirmDeleteSelected = async () => {
  if (selectedFileIds.value.length === 0) return

  try {
    const results = await filesService.deleteMultipleFiles(selectedFileIds.value)

    const successCount = results.filter((r) => r.success).length
    const failCount = results.filter((r) => !r.success).length

    if (successCount > 0) {
      showSuccess(`Deleted ${successCount} file(s)`)
    }

    if (failCount > 0) {
      showError(`Failed to delete ${failCount} file(s)`)
    }

    selectedFileIds.value = []
    await loadFiles()
    await loadFileGroups()
    if (storageWidget.value) {
      await storageWidget.value.refresh()
    }
  } catch (error) {
    console.error('Delete error:', error)
    showError('Failed to delete files')
  } finally {
    isDeleteSelectedOpen.value = false
  }
}

const cancelDeleteSelected = () => {
  isDeleteSelectedOpen.value = false
}

const deleteFile = (fileId: number) => {
  fileToDelete.value = fileId
  isConfirmOpen.value = true
}

const confirmDelete = async () => {
  if (!fileToDelete.value) return

  try {
    await filesService.deleteFile(fileToDelete.value)
    showSuccess('File deleted successfully')
    await loadFiles()
    await loadFileGroups()
    if (storageWidget.value) {
      await storageWidget.value.refresh()
    }
  } catch (error) {
    console.error('Delete error:', error)
    showError('Failed to delete file')
  } finally {
    isConfirmOpen.value = false
    fileToDelete.value = null
  }
}

const cancelDelete = () => {
  isConfirmOpen.value = false
  fileToDelete.value = null
}

const viewFileContent = (fileId: number) => {
  selectedFileId.value = fileId
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  selectedFileId.value = null
}

const downloadFile = async (fileId: number, filename: string) => {
  try {
    await filesService.downloadFile(fileId, filename)
    showSuccess('File downloaded successfully')
  } catch (error) {
    console.error('Download error:', error)
    showError('Failed to download file')
  }
}

const closeShareModal = () => {
  isShareModalOpen.value = false
  shareFileId.value = null
  shareFileName.value = ''
}

const handleShared = async () => {
  showSuccess('File is now publicly accessible')
  await loadFiles()
}

const handleUnshared = async () => {
  showSuccess('Public access revoked')
  await loadFiles()
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    loadFiles(currentPage.value + 1)
  }
}

const previousPage = () => {
  if (currentPage.value > 1) {
    loadFiles(currentPage.value - 1)
  }
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

// Load initial data
onMounted(async () => {
  document.addEventListener('click', closeFolderMenu)
  await Promise.all([loadFileGroups(), loadFiles()])

  const persistedFiles = loadFileMetadata()
  if (persistedFiles && persistedFiles.length > 0) {
    showInfo(
      t('files.filesLostAfterReload', {
        count: persistedFiles.length,
        names: persistedFiles.map((f) => f.name).join(', '),
      })
    )
    clearFiles()
  }
})

onUnmounted(() => {
  document.removeEventListener('click', closeFolderMenu)
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.dialog-fade-enter-active,
.dialog-fade-leave-active {
  transition: opacity 0.2s ease;
}

.dialog-fade-enter-from,
.dialog-fade-leave-to {
  opacity: 0;
}

.animate-scale-in {
  animation: scale-in 0.2s ease-out;
}

@keyframes scale-in {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
