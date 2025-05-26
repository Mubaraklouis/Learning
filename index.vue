<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
  BookOpen, FileText, Trash2, Search, Save, Star, StarOff, Copy, Edit, Menu,
  Bold, Italic, List, CheckSquare, Heading1, File, Image, Paperclip, LayoutGrid,
  Clock, Settings, SortAsc, FolderOpen, X, ChevronRight,
  Video, Music
} from 'lucide-vue-next';
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Link from '@tiptap/extension-link';
import ImageExtension from '@tiptap/extension-image';
import Highlight from '@tiptap/extension-highlight';
import CodeBlock from '@tiptap/extension-code-block';
import type { BreadcrumbItemType } from '@/types';
import { router } from '@inertiajs/vue3';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Define props
const props = defineProps<{
  folders: {
    id: string;
    name: string;
    created_at: string;
    notes?: {
      id: string;
      title: string;
      content: string;
      folder_id: string;
      is_favorite: boolean;
      last_edited: string;
      created_at: string;
    }[];
  }[];
}>();

// Define breadcrumbs
const breadcrumbs: BreadcrumbItemType[] = [
  {
    title: 'Notes',
    href: '/notes'
  }
];

// Note interface
interface Note {
  id: string;
  title: string;
  content: string;
  folder_id: string;
  is_favorite: boolean;
  last_edited: string;
  created_at: string;
  attachments?: {
    id: string;
    name: string;
    type: string;
    url: string;
    size: number;
    created_at: string;
  }[];
  has_attachments?: boolean;
}

// Folder interface
interface Folder {
  id: string;
  name: string;
  created_at: string;
  notes?: Note[];
}

// Initialize folders and notes from props
const folders = ref<Folder[]>(props.folders || []);
// Make sure we include all notes, even those with manually added files
const notes = ref<Note[]>(props.folders?.flatMap(folder => {
  // Ensure notes have their folder_id set
  return (folder.notes || []).map(note => ({
    ...note,
    folder_id: note.folder_id || folder.id, // Ensure folder_id is set
    // Handle both new API structure (files) and old one (attachments) for backward compatibility
    attachments: note.files || note.attachments || [],
    files: note.files || []
  }));
}) || []);

// Add default "All Notes" folder if not present
if (!folders.value.some(folder => folder.id === 'default')) {
  folders.value.unshift({
    id: 'default',
    name: 'All Notes',
    created_at: new Date().toISOString(),
    notes: []
  });
}

// User interface state
const selectedFolderId = ref('default');
const searchQuery = ref('');
const sortBy = ref<'title' | 'createdAt'>('createdAt');
const selectedNoteId = ref<string | null>('1');
const isEditing = ref(false);
const showSidebar = ref(true);
const showNewNoteDialog = ref(false);
const showNewFolderDialog = ref(false);
const showDeleteNoteDialog = ref(false);
const newNoteTitle = ref('');
const newFolderName = ref('');
const noteToDelete = ref<string | null>(null);
const showFavoritesOnly = ref(false);

// File management state
const showDeleteFileDialog = ref(false);
const fileToDelete = ref<string | null>(null);
const fileToDeleteName = ref<string>('');
const deletingFile = ref(false);

// Add file upload state
const showFileUploadDialog = ref(false);
const uploadingFile = ref(false);
const selectedFile = ref<File | null>(null);
const selectedNoteForUpload = ref<string | null>(null);

// Image preview state
const showImagePreviewDialog = ref(false);
const previewImageUrl = ref<string>('');

// Add file input ref
const fileInput = ref<HTMLInputElement | null>(null);

// Computed properties
const filteredNotes = computed(() => {
  let filtered = notes.value;
  
  // Special case for attachment filter
  if (selectedFolderId.value === 'attachments') {
    return notesWithAttachments.value;
  }
  
  // Filter by folder
  if (selectedFolderId.value !== 'default') {
    filtered = filtered.filter(note => note.folder_id === selectedFolderId.value);
  }
  
  // Filter favorites
  if (showFavoritesOnly.value) {
    filtered = filtered.filter(note => note.is_favorite);
  }
  
  // Filter by search query
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    filtered = filtered.filter(note => 
      (note.title && note.title.toLowerCase().includes(query)) ||
      (note.content && note.content.toLowerCase().includes(query)) ||
      (note.attachments && note.attachments.some(file => 
        (file.name && file.name.toLowerCase().includes(query))
      ))
    );
  }
  
  // Sort notes
  if (sortBy.value === 'title') {
    filtered.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
  } else if (sortBy.value === 'createdAt') {
    filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  } else if (sortBy.value === 'updatedAt') {
    filtered.sort((a, b) => {
      const aDate = a.last_edited ? new Date(a.last_edited) : new Date(a.created_at);
      const bDate = b.last_edited ? new Date(b.last_edited) : new Date(b.created_at);
      return bDate - aDate;
    });
  }
  
  return filtered;
});

const selectedNote = computed(() => {
  return notes.value.find(note => note.id === selectedNoteId.value) || null;
});

const selectedFolder = computed(() => {
  if (selectedFolderId.value === 'default') {
    return { id: 'default', name: 'All Notes' };
  } else if (selectedFolderId.value === 'attachments') {
    return { id: 'attachments', name: 'Notes with Attachments' };
  }
  return folders.value.find(folder => folder.id === selectedFolderId.value) || folders.value[0];
});

// Add computed property for notes with attachments
const notesWithAttachments = computed(() => {
  return notes.value.filter(note => {
    // Check both files and attachments properties
    return (
      (note.files && Array.isArray(note.files) && note.files.length > 0) ||
      (note.attachments && Array.isArray(note.attachments) && note.attachments.length > 0)
    );
  });
});

// Function to handle editor auto-scrolling
function handleEditorScroll() {
  if (!editor.value || !isEditing.value) return;
  
  // Get the editor container and its content
  const editorContainer = document.querySelector('.editor-container');
  const proseMirror = editorContainer?.querySelector('.ProseMirror');
  if (!editorContainer || !proseMirror) return;
  
  // Get the current selection
  const selection = editor.value.view.state.selection;
  const editorView = editor.value.view;
  
  // Get the DOM node and offset of the current cursor position
  const pos = selection.$head;
  const node = editorView.domAtPos(pos.pos);
  const cursorElement = node.node;
  
  if (cursorElement instanceof Element) {
    // Get cursor position relative to the editor container
    const containerRect = editorContainer.getBoundingClientRect();
    const cursorRect = cursorElement.getBoundingClientRect();
    
    // Calculate the distance from the cursor to the bottom of the container
    const distanceToBottom = containerRect.bottom - cursorRect.bottom;
    
    // If cursor is within 150px of the bottom, scroll down
    if (distanceToBottom < 150) {
      const scrollAmount = 150 - distanceToBottom;
      editorContainer.scrollBy({
        top: scrollAmount,
        behavior: 'smooth'
      });
    }
  }
}

// Editor setup
const editor = useEditor({
  content: selectedNote.value?.content || '',
  extensions: [
    StarterKit,
    Placeholder.configure({
      placeholder: 'Start writing...'
    }),
    TaskList.configure({
      HTMLAttributes: {
        class: 'apple-notes-task-list',
      },
    }),
    TaskItem.configure({
      nested: true,
      HTMLAttributes: {
        class: 'apple-notes-task-item',
      },
    }),
    Link.configure({
      openOnClick: false,
      HTMLAttributes: {
        class: 'text-primary underline'
      }
    }),
    ImageExtension,
    Highlight,
    CodeBlock
  ],
  editable: isEditing.value,
  onUpdate: () => {
    // Call handleEditorScroll on every content update
    handleEditorScroll();
  },
  onSelectionUpdate: () => {
    // Also handle scrolling when selection changes (e.g., arrow keys)
    handleEditorScroll();
  }
});

// Watch for changes to selected note and update editor content
watch(selectedNote, (newNote) => {
  if (editor.value && newNote) {
    editor.value.commands.setContent(newNote.content);
    editor.value.setEditable(isEditing.value);
  }
});

// Watch for changes to isEditing and update editor editable state
watch(isEditing, (newValue) => {
  if (editor.value) {
    editor.value.setEditable(newValue);
  }
});

async function saveNoteContent(content: string) {
  if (selectedNote.value) {
    try {
      await router.put(route('notes.update', { id: selectedNote.value.id }), {
        title: selectedNote.value.title,
        content: content,
        folder_id: selectedNote.value.folder_id,
        is_favorite: selectedNote.value.is_favorite
      });
    } catch (error) {
      console.error('Error saving note:', error);
    }
  }
}

// Format date for display
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const formatDate = (dateString: string): string => {
  const date = new Date(dateString);
  
  // Check if date is valid
  if (isNaN(date.getTime())) {
    return 'Invalid date';
  }
  
  // Format the date
  const options: Intl.DateTimeFormatOptions = { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  };
  
  return new Intl.DateTimeFormat('en-US', options).format(date);
};

// Format note preview for display in list view
function formatNotePreview(content: string): string {
  if (!content) return '';
  
  // Remove HTML tags and decode entities
  const plainText = content.replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim();
  
  // Limit to a reasonable preview length
  return plainText.length > 120 ? plainText.substring(0, 120) + '...' : plainText;
}

// Format date in relative format (e.g., "2 days ago")
function formatDateRelative(dateString: string): string {
  if (!dateString) return '';
  
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return 'Invalid date';
  
  const now = new Date();
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);
  
  // Less than a minute
  if (diffInSeconds < 60) {
    return 'Just now';
  }
  
  // Less than an hour
  const diffInMinutes = Math.floor(diffInSeconds / 60);
  if (diffInMinutes < 60) {
    return `${diffInMinutes} minute${diffInMinutes > 1 ? 's' : ''} ago`;
  }
  
  // Less than a day
  const diffInHours = Math.floor(diffInMinutes / 60);
  if (diffInHours < 24) {
    return `${diffInHours} hour${diffInHours > 1 ? 's' : ''} ago`;
  }
  
  // Less than a week
  const diffInDays = Math.floor(diffInHours / 24);
  if (diffInDays < 7) {
    return `${diffInDays} day${diffInDays > 1 ? 's' : ''} ago`;
  }
  
  // Format with month and day
  const options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };
  if (now.getFullYear() === date.getFullYear()) {
    return new Intl.DateTimeFormat('en-US', options).format(date);
  }
  
  // Different year, include year in format
  return new Intl.DateTimeFormat('en-US', { ...options, year: 'numeric' }).format(date);
}

// Function to handle note selection
function handleNoteSelection(noteId: string) {
  if (!noteId) return;
  selectedNoteId.value = noteId;
  
  // Hide sidebar on mobile when a note is selected
  if (window.innerWidth < 768) {
    showSidebar.value = false;
  }
}

// Toggle favorite state for a note
function toggleFavorite(noteId: string) {
  if (!noteId) return;
  const note = notes.value.find(n => n.id === noteId);
  if (!note) return;
  
  note.is_favorite = !note.is_favorite;
  // In a real app, you would call an API here to update the server
}

// Duplicate a note
function duplicateNote(noteId: string) {
  if (!noteId) return;
  const note = notes.value.find(n => n.id === noteId);
  if (!note) return;
  
  // Create a duplicate with a new ID and updated title
  const duplicate = {
    ...note,
    id: 'temp-' + Date.now().toString(),
    title: `${note.title} (Copy)`,
    created_at: new Date().toISOString(),
    last_edited: new Date().toISOString()
  };
  
  notes.value.push(duplicate);
  // In a real app, you would call an API here to save the duplicate
}

// Preview a file
function previewFile(file: any) {
  if (!file || !file.url) return;
  
  // Handle different file types differently
  if (file.type && file.type.startsWith('image/')) {
    previewImageUrl.value = file.url;
    showImagePreviewDialog.value = true;
  } else {
    // Open non-image files in a new tab
    window.open(file.url, '_blank');
  }
}

// Show delete file confirmation
function showDeleteFileConfirmation(fileId: string) {
  if (!fileId) return;
  
  fileToDelete.value = fileId;
  
  // Find file name for confirmation dialog
  if (selectedNote.value && selectedNote.value.attachments) {
    const file = selectedNote.value.attachments.find(f => f.id === fileId);
    if (file) {
      fileToDeleteName.value = file.name;
    }
  }
  
  showDeleteFileDialog.value = true;
}

// Set editor formatting
function setEditorFormat(command: string, level?: number) {
  if (!editor.value) return;
  
  if (command === 'heading' && level) {
    editor.value.chain().focus().toggleHeading({ level }).run();
  } else if (command === 'paragraph') {
    editor.value.chain().focus().setParagraph().run();
  } else {
    switch (command) {
      case 'bold':
        editor.value.chain().focus().toggleBold().run();
        break;
      case 'italic':
        editor.value.chain().focus().toggleItalic().run();
        break;
      case 'bulletList':
        editor.value.chain().focus().toggleBulletList().run();
        break;
      case 'taskList':
        editor.value.chain().focus().toggleTaskList().run();
        break;
      default:
        break;
    }
  }
}

// Delete a note
function deleteNote() {
  if (!noteToDelete.value) return;
  
  // Find and remove the note from the notes array
  const index = notes.value.findIndex(note => note.id === noteToDelete.value);
  if (index !== -1) {
    notes.value.splice(index, 1);
  }
  
  // Clear selection if the deleted note was selected
  if (selectedNoteId.value === noteToDelete.value) {
    selectedNoteId.value = notes.value.length > 0 ? notes.value[0].id : null;
  }
  
  // Reset delete state
  noteToDelete.value = null;
  showDeleteNoteDialog.value = false;
  
  // In a real app, you would call an API here to delete the note
}

// Upload file
function uploadFile() {
  if (!selectedFile.value || !selectedNoteForUpload.value) return;
  
  // Simulate file upload
  uploadingFile.value = true;
  
  // Artificially delay to simulate upload
  setTimeout(() => {
    // Create a new attachment
    const newFile = {
      id: 'temp-' + Date.now().toString(),
      name: selectedFile.value?.name,
      type: selectedFile.value?.type,
      url: URL.createObjectURL(selectedFile.value),
      size: selectedFile.value?.size,
      created_at: new Date().toISOString()
    };
    
    // Find the note and add the attachment
    const note = notes.value.find(n => n.id === selectedNoteForUpload.value);
    if (note) {
      if (!note.attachments) {
        note.attachments = [];
      }
      note.attachments.push(newFile);
    }
    
    // Reset state
    selectedFile.value = null;
    uploadingFile.value = false;
    showFileUploadDialog.value = false;
    
    // In a real app, you would call an API here to upload the file
  }, 1000);
}

// Add a computed property for current heading level
const currentHeadingLevel = computed(() => {
  if (!editor.value) return 0;
  if (editor.value.isActive('heading', { level: 1 })) return 1;
  if (editor.value.isActive('heading', { level: 2 })) return 2;
  if (editor.value.isActive('heading', { level: 3 })) return 3;
  return 0;
})

// Get file details for an attachment
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const getAttachmentWithDetails = (attachment) => {
  if (!attachment) return null;
  
  // Convert size to readable format if not already done
  const fileWithDetails = {
    ...attachment,
    // If size is a number, format it, otherwise keep original
    formattedSize: typeof attachment.size === 'number' ? formatFileSize(attachment.size) : attachment.size,
    // Get icon based on file type
    typeLabel: getFileTypeLabel(attachment.type || getFileExtension(attachment.name)),
    icon: getFileIcon(attachment.type || getFileExtension(attachment.name))
  };
  
  return fileWithDetails;
};

// ... (rest of the code remains the same)

// Helper function to get folder name for a note
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const getFolderNameForNote = (noteId: string): string => {
  // Find the note first
  const note = notes.value.find(n => n.id === noteId);
  if (!note) return 'Unknown Folder';
  
  // Find the folder that contains this note
  const folder = folders.value.find(f => f.id === note.folder_id);
  return folder ? folder.name : 'Unknown Folder';
};

// ... (rest of the code remains the same)

// Open file in new tab
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const openFileInNewTab = (url: string) => {
  if (!url) return;
  window.open(url, '_blank');
};

// ... (rest of the code remains the same)

// Delete file
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const deleteFile = async () => {
  if (!fileToDelete.value) return;
  deletingFile.value = true;
  
  try {
    const response = await fetch(`/api/files/${fileToDelete.value}`, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      }
    });
    
    if (response.ok) {
      // Update the selected note to remove the file
      if (selectedNote.value) {
        const updatedFiles = selectedNote.value.files?.filter(file => file.id !== fileToDelete.value) || [];
        selectedNote.value.files = updatedFiles;
      }
      showDeleteFileDialog.value = false;
    } else {
      console.error('Failed to delete file');
    }
  } catch (error) {
    console.error('Error deleting file:', error);
  } finally {
    deletingFile.value = false;
    fileToDelete.value = null;
    fileToDeleteName.value = '';
  }
};

// Get file details from note and folder structure
// eslint-disable-next-line @typescript-eslint/no-unused-vars
function getAttachmentDetails(noteId: string, fileId: string) {
  const note = notes.value.find(n => n.id === noteId);
  if (!note || !note.attachments) return null;
  
  const file = note.attachments.find(f => f.id === fileId);
  if (!file) return null;
  
  const folder = folders.value.find(f => f.id === note.folder_id);
  
  return {
    ...file,
    noteTitle: note.title || 'Untitled Note',
    folderName: folder?.name || 'Unknown Folder'
  };
}

</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="h-full flex">
      <!-- Hidden file input for uploads -->
      <input 
        ref="fileInput"
        type="file"
        class="hidden"
        @change="handleFileUpload"
      />
      <!-- Notes Sidebar -->
      <aside
        class="fixed inset-y-0 left-0 z-40 w-full md:relative md:w-72 xl:w-80 border-r border-border/60 bg-background/95 dark:bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 transition-all duration-300 ease-in-out shadow-sm dark:shadow-md dark:shadow-black/10 overflow-hidden"
        :class="{
          'translate-x-0': showSidebar,
          '-translate-x-full md:translate-x-0': !showSidebar
        }"
      >
        <div class="h-full flex flex-col">
          <!-- Sidebar Header -->
          <div class="flex justify-between items-center p-3 border-b border-border/60 bg-secondary/10 dark:bg-secondary/5 transition-colors">
            <div class="flex items-center gap-2 text-foreground transition-colors pl-1">
              <BookOpen class="h-5 w-5 text-primary" />
              <span class="text-base font-medium">My Notes</span>
            </div>
            <div class="flex items-center gap-1 sm:gap-3">
              <Button 
                size="sm" 
                variant="ghost" 
                @click="showNewNoteDialog = true" 
                title="New Note" 
                class="h-8 px-2 text-xs rounded-full flex items-center gap-1 text-primary border border-primary/20 bg-primary/5"
              >
                <Plus class="h-4 w-4 mr-1" />
                <span class="hidden sm:inline">New</span>
              </Button>
              <Button 
                size="icon" 
                variant="ghost" 
                @click="fileInput?.click()" 
                title="Upload File" 
                class="h-8 w-8 rounded-full flex items-center justify-center bg-secondary/40 dark:bg-secondary/20 hover:bg-primary/10 dark:hover:bg-primary/20 transition-colors"
              >
                <Paperclip class="h-4 w-4 text-primary" />
              </Button>
              <Button 
                size="icon" 
                variant="ghost" 
                @click="showSidebar = !showSidebar" 
                class="h-8 w-8 md:hidden hover:bg-primary/10 dark:hover:bg-primary/20"
              >
                <Menu class="h-4 w-4" />
              </Button>
            </div>
          </div>

          <div class="flex-1 overflow-y-auto custom-scrollbar">
            <!-- Search -->
            <div class="p-3 pb-2">
              <div class="relative">
                <Search class="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  v-model="searchQuery"
                  placeholder="Search notes..."
                  class="pl-9 py-5 h-10 bg-background dark:bg-background/40 border-border/50 dark:border-border/40 text-sm transition-colors shadow-sm focus-visible:ring-1 focus-visible:ring-primary/30"
                />
              </div>
            </div>
            
            <!-- Navigation Options -->
            <div class="px-3 pt-1 pb-4">
              <div class="space-y-1">
                <!-- All Notes -->
                <Button
                  variant="ghost"
                  class="w-full justify-start gap-2 h-10 hover:bg-primary/10 dark:hover:bg-primary/20 font-normal text-sm"
                  :class="selectedFolderId === 'default' && !showFavoritesOnly ? 'bg-primary/15 dark:bg-primary/25 text-primary font-medium' : ''"
                  @click="selectedFolderId = 'default'; showFavoritesOnly = false"
                >
                  <LayoutGrid class="h-4 w-4" />
                  <span>All Notes</span>
                  <span class="ml-auto text-xs px-2 py-0.5 rounded-md bg-muted/70 dark:bg-muted/40 text-muted-foreground/80">
                    {{ notes.length }}
                  </span>
                </Button>
                
                <!-- Favorites -->
                <Button
                  variant="ghost"
                  class="w-full justify-start gap-2 h-10 hover:bg-primary/10 dark:hover:bg-primary/20 font-normal text-sm"
                  :class="showFavoritesOnly ? 'bg-primary/15 dark:bg-primary/25 text-primary font-medium' : ''"
                  @click="showFavoritesOnly = true; selectedFolderId = 'default'"
                >
                  <Star class="h-4 w-4" :class="showFavoritesOnly ? 'text-amber-500' : ''" />
                  <span>Favorites</span>
                  <span class="ml-auto text-xs px-2 py-0.5 rounded-md bg-muted/70 dark:bg-muted/40 text-muted-foreground/80">
                    {{ notes.filter(note => note.is_favorite).length }}
                  </span>
                </Button>
                
                <!-- Recent -->
                <Button
                  variant="ghost"
                  class="w-full justify-start gap-2 h-10 hover:bg-primary/10 dark:hover:bg-primary/20 font-normal text-sm"
                  :class="sortBy === 'createdAt' && selectedFolderId === 'default' && !showFavoritesOnly ? 'bg-primary/10 text-primary' : ''"
                  @click="sortBy = 'createdAt'; selectedFolderId = 'default'; showFavoritesOnly = false"
                >
                  <Clock class="h-4 w-4" />
                  <span>Recent</span>
                </Button>
                
                <!-- With Attachments -->
                <Button
                  variant="ghost"
                  class="w-full justify-start gap-2 h-10 hover:bg-primary/10 dark:hover:bg-primary/20 font-normal text-sm"
                  :class="selectedFolderId === 'attachments' ? 'bg-primary/15 dark:bg-primary/25 text-primary font-medium' : ''"
                  @click="selectedFolderId = 'attachments'; showFavoritesOnly = false"
                >
                  <Paperclip class="h-4 w-4" />
                  <span>With Attachments</span>
                  <span class="ml-auto text-xs px-2 py-0.5 rounded-md bg-muted/70 dark:bg-muted/40 text-muted-foreground/80">
                    {{ notesWithAttachments.length }}
                  </span>
                </Button>
              </div>
            </div>
            
            <!-- Folders -->
            <div class="px-3 mb-3">
              <div class="flex items-center justify-between mb-2">
                <h3 class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Folders</h3>
                <Button 
                  size="icon" 
                  variant="ghost" 
                  @click="showNewFolderDialog = true" 
                  title="New Folder" 
                  class="h-6 w-6 hover:bg-primary/10 dark:hover:bg-primary/20"
                >
                  <Plus class="h-3.5 w-3.5" />
                </Button>
              </div>
              <div class="space-y-0.5 rounded-lg overflow-hidden border border-border/30 dark:border-border/20 bg-background/50 dark:bg-background/30 shadow-sm">
                <button
                  v-for="folder in folders.filter(f => f.id !== 'default')"
                  :key="folder.id"
                  @click="selectedFolderId = folder.id; showFavoritesOnly = false"
                  class="w-full flex items-center px-3 py-2 transition-colors text-left hover:bg-muted/30 dark:hover:bg-muted/20 border-b border-border/20 dark:border-border/10 last:border-0"
                  :class="selectedFolderId === folder.id ? 'bg-primary/10 dark:bg-primary/20 text-primary' : 'text-foreground/80'"
                >
                  <component :is="selectedFolderId === folder.id ? FolderOpen : FileText" class="h-4 w-4 mr-2 flex-shrink-0" />
                  <span class="truncate text-sm">{{ folder.name }}</span>
                  <span class="ml-auto text-xs px-1.5 py-0.5 rounded-md bg-muted/70 dark:bg-muted/40 text-muted-foreground/80">
                    {{ notes.filter(note => note.folder_id === folder.id).length }}
                  </span>
                </button>
              </div>
            </div>
            
            <!-- Sort Options -->
            <div class="px-3 mb-3">
              <div class="flex items-center justify-between">
                <h3 class="text-xs font-medium uppercase tracking-wider text-muted-foreground mb-2">Sort By</h3>
                <div class="flex items-center gap-1 bg-muted/50 dark:bg-muted/30 rounded-md p-0.5">
                  <Button
                    size="sm"
                    variant="ghost"
                    @click="sortBy = 'createdAt'"
                    class="h-7 px-2 text-xs rounded-sm"
                    :class="sortBy === 'createdAt' ? 'bg-background dark:bg-background/80 text-foreground shadow-sm' : 'text-muted-foreground'"
                  >
                    <Clock class="h-3 w-3 mr-1" />
                    Date
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    @click="sortBy = 'title'"
                    class="h-7 px-2 text-xs rounded-sm"
                    :class="sortBy === 'title' ? 'bg-background dark:bg-background/80 text-foreground shadow-sm' : 'text-muted-foreground'"
                  >
                    <SortAsc class="h-3 w-3 mr-1" />
                    Name
                  </Button>
                </div>
              </div>
            </div>

            <!-- Attachments Section (Only visible when note is selected and has attachments) -->
            <div v-if="selectedNote && selectedNote.files && selectedNote.files.length > 0" class="mt-6 border border-border/40 dark:border-border/30 rounded-xl overflow-hidden">
              <div class="flex items-center justify-between px-4 py-3 bg-muted/30 dark:bg-muted/20 border-b border-border/40 dark:border-border/30">
                <div class="flex items-center gap-2">
                  <Paperclip class="h-4 w-4 text-muted-foreground/70" />
                  <h3 class="text-sm font-medium">Attachments</h3>
                </div>
                <span class="text-xs text-muted-foreground/70 bg-muted/50 dark:bg-muted/30 px-2 py-0.5 rounded-md">
                  {{ selectedNote.files.length }} file{{ selectedNote.files.length !== 1 ? 's' : '' }}
                </span>
              </div>
              <div class="divide-y divide-border/40 dark:divide-border/30">
                <div 
                  v-for="file in selectedNote.files" 
                  :key="file.id" 
                  class="group flex items-center justify-between px-4 py-2.5 hover:bg-muted/30 dark:hover:bg-muted/20 transition-colors duration-200"
                >
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-primary/10 dark:bg-primary/5 flex items-center justify-center">
                      <component :is="getFileIcon(file.file_name)" class="h-4 w-4 text-primary" />
                    </div>
                    <div class="min-w-0">
                      <span class="text-sm font-medium truncate block">{{ file.file_name }}</span>
                      <div class="flex items-center gap-2 text-xs text-muted-foreground/70">
                        <span>{{ getFileTypeLabel(file.file_name) }}</span>
                        <span class="inline-block w-1 h-1 rounded-full bg-muted-foreground/30"></span>
                        <span>{{ formatFileSize(file.size || 0) }}</span>
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center gap-1 ml-4">
                    <Button 
                      variant="ghost" 
                      size="icon" 
                      class="h-8 w-8 opacity-0 group-hover:opacity-100 transition-opacity duration-200 hover:bg-primary/10 dark:hover:bg-primary/20" 
                      @click="previewFile(file)"
                      :title="'View ' + getFileTypeLabel(file.file_name).toLowerCase()"
                    >
                      <component :is="getFileIcon(file.file_name)" class="h-4 w-4" />
                    </Button>
                    <Button 
                      variant="ghost" 
                      size="icon" 
                      class="h-8 w-8 opacity-0 group-hover:opacity-100 transition-opacity duration-200 hover:bg-destructive/10 dark:hover:bg-destructive/20 text-destructive" 
                      @click="showDeleteFileConfirmation(file.id)"
                      title="Delete file"
                    >
                      <Trash2 class="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Image Preview Dialog -->
            <Dialog :open="showImagePreviewDialog" @update:open="showImagePreviewDialog = false">
              <DialogContent class="sm:max-w-[800px] p-0 overflow-hidden">
                <div class="relative w-full h-full max-h-[80vh] bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
                  <img 
                    :src="previewImageUrl" 
                    class="w-full h-full object-contain"
                    alt="Preview"
                  />
                  <Button
                    variant="ghost"
                    size="icon"
                    class="absolute top-2 right-2 rounded-full bg-background/80 hover:bg-background/90"
                    @click="showImagePreviewDialog = false"
                  >
                    <X class="h-4 w-4" />
                  </Button>
                </div>
              </DialogContent>
            </Dialog>

            <!-- Attachments Section (when note is selected) -->
            <div v-if="selectedNote" class="mt-6 border border-border/40 dark:border-border/30 rounded-xl overflow-hidden">
              <div class="flex items-center justify-between px-4 py-3 bg-muted/30 dark:bg-muted/20">
                <div class="flex items-center gap-2">
                  <Paperclip class="h-4 w-4 text-muted-foreground/70" />
                  <h3 class="text-sm font-medium flex items-center gap-2">
                    Attachments
                    <span class="text-xs text-muted-foreground/70 bg-muted/50 dark:bg-muted/30 px-2 py-0.5 rounded-md">
                      {{ selectedNote.files.length }} file{{ selectedNote.files.length !== 1 ? 's' : '' }}
                    </span>
                  </h3>
                </div>
                <Button 
                  variant="ghost" 
                  size="sm" 
                  class="hover:bg-primary/10 dark:hover:bg-primary/20 text-primary" 
                  @click="showFileUploadDialog = true"
                >
                  <Plus class="h-4 w-4 mr-1.5" />
                  Add File
                </Button>
              </div>
            </div>

            <!-- Notes List -->
            <div class="px-3 pb-3">
              <div class="flex items-center justify-between sticky top-0 z-10 bg-background/95 dark:bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-1 py-2 -mx-1 mb-2">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-foreground/90 dark:text-foreground/80">{{ selectedFolder.name }}</h3>
                <span class="text-xs px-1.5 py-0.5 rounded-md bg-primary/10 dark:bg-primary/20 text-primary/90">
                  {{ filteredNotes.length }} note{{ filteredNotes.length !== 1 ? 's' : '' }}
                </span>
              </div>

              <div v-if="filteredNotes.length === 0" class="text-center py-10 border border-dashed border-border/50 dark:border-border/30 rounded-lg">
                <div class="text-muted-foreground/60">
                  <FileText class="h-12 w-12 mx-auto mb-3 opacity-40" />
                  <p class="text-sm font-medium mb-1">No notes found</p>
                  <p class="text-xs text-muted-foreground/70">Create a new note to get started</p>
                  <Button
                    size="sm"
                    variant="outline"
                    @click="showNewNoteDialog = true"
                    class="mt-4 border-primary/30 text-primary hover:bg-primary/10 dark:hover:bg-primary/20"
                  >
                    <Plus class="h-4 w-4 mr-1" />
                    Create Note
                  </Button>
                </div>
              </div>

              <div v-else class="grid gap-2">
                <div 
                  v-for="note in filteredNotes" 
                  :key="note.id"
                  @click="handleNoteSelection(note.id)"
                  class="border border-border/30 dark:border-border/20 rounded-lg p-3 cursor-pointer transition-all duration-200 hover:border-border/70 dark:hover:border-border/50 group dark:hover:bg-muted/10"
                  :class="{ 'bg-primary/10 dark:bg-primary/20 border-primary/30 dark:border-primary/40 shadow-sm' : selectedNoteId === note.id }"
                >
                  <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 w-10 h-10 flex items-center justify-center bg-primary/10 dark:bg-primary/20 rounded-md mt-0.5">
                      <FileText class="h-5 w-5 text-primary/80" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center justify-between">
                        <h4 class="text-sm font-medium truncate text-foreground group-hover:text-primary transition-colors">
                          {{ note.title || 'Untitled Note' }}
                        </h4>
                        <div class="flex items-center gap-1 opacity-70 group-hover:opacity-100 transition-opacity">
                          <Button 
                            size="icon" 
                            variant="ghost" 
                            @click.stop="toggleFavorite(note.id)" 
                            class="h-6 w-6 hover:bg-muted dark:hover:bg-muted/30"
                          >
                            <Star v-if="note.is_favorite" class="h-3.5 w-3.5 text-amber-500 fill-amber-500" />
                            <StarOff v-else class="h-3.5 w-3.5 text-muted-foreground/60" />
                          </Button>
                        </div>
                      </div>
                      <div class="text-xs text-muted-foreground line-clamp-1 mt-1 mb-2 group-hover:text-foreground/80 transition-colors">
                        {{ formatNotePreview(note.content) }}
                      </div>
                      <div class="flex items-center justify-between text-xs text-muted-foreground mt-1">
                        <div class="flex items-center gap-1 flex-shrink-0">
                          <Clock class="h-3 w-3" />
                          <span>{{ formatDateRelative(note.updated_at || note.created_at) }}</span>
                        </div>
                        <div v-if="note.attachments?.length || note.files?.length" class="flex items-center gap-1 bg-muted/50 dark:bg-muted/30 px-1.5 py-0.5 rounded-md cursor-pointer hover:bg-primary/10 dark:hover:bg-primary/20 transition-colors" @click.stop="handleNoteSelection(note.id)">
                          <Paperclip class="h-3 w-3" />
                          <span class="text-xs text-muted-foreground/90">{{ note.attachments?.length || note.files?.length }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </aside>

      <!-- Main Content -->
      <main class="flex-1 flex flex-col h-[calc(100vh-4rem)] transition-colors relative max-w-full">
        <!-- Hidden file input for uploads -->
        <input 
          ref="fileInput"
          type="file"
          class="hidden"
          @change="handleFileUpload"
        />
        <div v-if="!selectedNote" class="flex-1 flex flex-col">
          <div class="p-3 border-b border-border flex items-center">
            <Button variant="ghost" size="icon" @click="showSidebar = !showSidebar" title="Toggle Notes Sidebar">
              <Menu class="h-4 w-4" />
            </Button>
          </div>
          <div class="flex-1 flex items-center justify-center p-4">
            <div class="text-center max-w-sm">
              <BookOpen class="h-12 w-12 mx-auto mb-4 text-muted-foreground/40" />
              <h3 class="text-lg font-medium mb-2">No Note Selected</h3>
              <p class="text-muted-foreground mb-4">Select a note from the sidebar or create a new one.</p>
              <div class="flex gap-3 justify-center">
                <Button variant="outline" @click="showSidebar = true" v-if="!showSidebar">
                  <Menu class="h-4 w-4 mr-2" />
                  Show Notes
                </Button>
                <Button @click="showNewNoteDialog = true">
                  <Plus class="h-4 w-4 mr-2" />
                  New Note
                </Button>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="flex-1 flex flex-col">
          <!-- Minimal Note Header -->
          <div class="p-2 sm:p-3 border-b border-border flex items-center justify-between bg-background/50">
            <div class="flex items-center gap-2">
              <Button variant="ghost" size="icon" @click="showSidebar = !showSidebar" title="Toggle Sidebar" class="h-8 w-8">
                <Menu class="h-4 w-4" />
              </Button>
            </div>
            <div class="flex items-center gap-1.5">
              <!-- Save Button -->
              <Button 
                v-if="isEditing"
                variant="ghost" 
                size="sm"
                @click="saveNoteContent(editor?.getHTML() || ''); isEditing = false;"
                class="h-8 px-2 text-xs rounded-full flex items-center gap-1 text-primary border border-primary/20 bg-primary/5"
                title="Save changes"
              >
                <Save class="h-3.5 w-3.5 mr-1" />
                Save
              </Button>
              
              <Button 
                variant="ghost" 
                size="sm"
                @click="toggleFavorite(selectedNote.id)"
                class="h-8 w-8 p-0"
                :class="selectedNote.is_favorite ? 'text-amber-500' : 'text-muted-foreground hover:text-foreground'"
                title="Toggle favorite"
              >
                <component :is="selectedNote.is_favorite ? Star : StarOff" class="h-4 w-4" />
              </Button>
              
              <!-- Edit/View Toggle -->
              <Button 
                variant="ghost" 
                size="sm"
                @click="isEditing = !isEditing"
                class="h-8 w-8 p-0"
                :class="isEditing ? 'text-primary' : 'text-muted-foreground hover:text-foreground'"
                title="Toggle edit mode"
              >
                <Edit class="h-4 w-4" />
              </Button>
              
              <!-- Note Actions Menu -->
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-foreground">
                    <Settings class="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem @click="duplicateNote(selectedNote.id)">
                    <Copy class="h-4 w-4 mr-2" />
                    Duplicate Note
                  </DropdownMenuItem>
                  <DropdownMenuItem @click="fileInput?.click()">
                    <Paperclip class="h-4 w-4 mr-2" />
                    Upload File
                  </DropdownMenuItem>
                  <DropdownMenuItem @click="noteToDelete = selectedNote.id; showDeleteNoteDialog = true" class="text-destructive">
                    <Trash2 class="h-4 w-4 mr-2" />
                    Delete Note
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </div>
          
          <!-- Apple Notes-like Minimal Formatting Bar (only when editing) -->
          <div v-if="isEditing" class="border-b border-border bg-background/50 p-1.5 flex items-center space-x-1 overflow-x-auto flex-wrap">
            <!-- Text Formatting -->
            <Button 
              v-for="(format, index) in [
                { icon: Bold, command: 'bold', title: 'Bold' },
                { icon: Italic, command: 'italic', title: 'Italic' }
              ]" 
              :key="index"
              size="sm" 
              variant="ghost" 
              class="h-7 w-7 p-0 rounded-full" 
              :class="editor?.isActive(format.command) ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'" 
              @click="setEditorFormat(format.command)" 
              :title="format.title"
            >
              <component :is="format.icon" class="h-3.5 w-3.5" />
            </Button>
            
            <!-- Separator -->
            <div class="h-5 w-px bg-border mx-1"></div>
            
            <!-- Lists -->
            <Button 
              v-for="(format, index) in [
                { icon: List, command: 'bulletList', title: 'Bullet List' },
                { icon: CheckSquare, command: 'taskList', title: 'Task List' }
              ]" 
              :key="index"
              size="sm" 
              variant="ghost" 
              class="h-7 w-7 p-0 rounded-full" 
              :class="editor?.isActive(format.command) ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'" 
              @click="setEditorFormat(format.command)" 
              :title="format.title"
            >
              <component :is="format.icon" class="h-3.5 w-3.5" />
            </Button>
            
            <!-- Separator -->
            <div class="h-5 w-px bg-border mx-1"></div>
            
            <!-- Heading Dropdown -->
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button 
                  size="sm" 
                  variant="ghost" 
                  class="h-7 px-2 text-xs rounded-full flex items-center gap-1"
                  :class="currentHeadingLevel ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                >
                  <Heading1 class="h-3.5 w-3.5" />
                  <span>{{ currentHeadingLevel ? `H${currentHeadingLevel}` : 'Text' }}</span>
                  <ChevronRight class="h-3 w-3 rotate-90" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start" class="min-w-[120px]">
                <DropdownMenuItem @click="setEditorFormat('paragraph')" :class="!currentHeadingLevel ? 'bg-accent text-accent-foreground' : ''">
                  <span class="text-sm">Normal Text</span>
                </DropdownMenuItem>
                <DropdownMenuItem @click="setEditorFormat('heading', 1)" :class="currentHeadingLevel === 1 ? 'bg-accent text-accent-foreground' : ''">
                  <span class="text-lg font-semibold">Heading 1</span>
                </DropdownMenuItem>
                <DropdownMenuItem @click="setEditorFormat('heading', 2)" :class="currentHeadingLevel === 2 ? 'bg-accent text-accent-foreground' : ''">
                  <span class="text-base font-semibold">Heading 2</span>
                </DropdownMenuItem>
                <DropdownMenuItem @click="setEditorFormat('heading', 3)" :class="currentHeadingLevel === 3 ? 'bg-accent text-accent-foreground' : ''">
                  <span class="text-sm font-semibold">Heading 3</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>

          <!-- Clean Editor Content -->
          <div class="flex-1 bg-background/40 h-full flex flex-col overflow-hidden w-full hide-scrollbar">
            <div class="mx-auto md:mx-24 p-4 md:p-6 lg:p-8 min-w-[320px] w-[calc(100%-1rem)] md:w-[calc(100%-3rem)] lg:w-[calc(100%-4rem)] max-w-5xl flex flex-col h-full overflow-hidden hide-scrollbar">
              <!-- Editor with forced scrolling -->
              <div 
  class="h-full overflow-auto editor-container w-full hide-scrollbar" 
  :class="{ 'editor-container--editing': isEditing }" 
  style="max-height: calc(100vh - 13rem); min-height: calc(100vh - 15rem); scroll-behavior: smooth;">

                <EditorContent :editor="editor" class="h-full" />
              </div>

            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- Dialogs -->
    <Dialog v-model:open="showNewNoteDialog">
      <DialogContent class="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Create New Note</DialogTitle>
        </DialogHeader>
        <form @submit.prevent="createNewNote">
          <div class="space-y-4 py-3">
            <div>
              <label for="note-title" class="text-sm font-medium block mb-1.5">Note Title</label>
              <Input
                id="note-title"
                v-model="newNoteTitle"
                placeholder="Enter a title for your note"
                class="w-full"
                autofocus
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" type="button" @click="showNewNoteDialog = false">Cancel</Button>
            <Button type="submit" :disabled="!newNoteTitle.trim()">Create Note</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="showNewFolderDialog">
      <DialogContent class="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Create New Folder</DialogTitle>
        </DialogHeader>
        <form @submit.prevent="createNewFolder">
          <div class="space-y-4 py-3">
            <div>
              <label for="folder-name" class="text-sm font-medium block mb-1.5">Folder Name</label>
              <Input
                id="folder-name"
                v-model="newFolderName"
                placeholder="Enter a name for your folder"
                class="w-full"
                autofocus
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" type="button" @click="showNewFolderDialog = false">Cancel</Button>
            <Button type="submit" :disabled="!newFolderName.trim()">Create Folder</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="showDeleteNoteDialog">
      <DialogContent class="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Delete Note</DialogTitle>
        </DialogHeader>
        <div class="py-3">
          <p>Are you sure you want to delete this note? This action cannot be undone.</p>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="showDeleteNoteDialog = false">Cancel</Button>
          <Button
            variant="destructive"
            @click="deleteNote()"
          >
            Delete Note
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Standard File Upload Modal -->
    <div v-if="showFileUploadDialog" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/50" @click="showFileUploadDialog = false"></div>
      
      <!-- Modal Content -->
      <div class="relative bg-background max-w-md w-full mx-4 rounded-lg shadow-lg overflow-hidden">
        <!-- Header -->
        <div class="p-4 border-b border-border flex items-center justify-between">
          <h2 class="text-lg font-medium">Upload File to Note</h2>
          <button @click="showFileUploadDialog = false" class="p-1 rounded-full hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>
        
        <!-- Body -->
        <div class="p-3 sm:p-4 max-h-[60vh] overflow-y-auto space-y-3 sm:space-y-4">
          <!-- File Preview -->
          <div v-if="selectedFile" class="flex items-center gap-4 p-4 rounded-lg border border-border/60 bg-muted/30 shadow-sm">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center">
              <component
                :is="getFileIconComponent(selectedFile.type)"
                class="h-6 w-6 text-primary"
              />
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium truncate">{{ selectedFile.name }}</p>
              <p class="text-xs text-muted-foreground mt-1">{{ getFileTypeLabel(selectedFile.type) }} • {{ formatFileSize(selectedFile.size) }}</p>
            </div>
          </div>

          <!-- Note Selection -->
          <div class="space-y-2">
            <label class="text-sm font-medium block mb-1.5">Select Note</label>
            <div class="max-h-64 overflow-y-auto custom-scrollbar p-3 border border-border/40 rounded-lg bg-background/50">
              <div 
                v-for="note in notes.value" 
                :key="note.id"
                @click="selectedNoteForUpload = note.id"
                class="mb-2 last:mb-0 p-2.5 rounded-md cursor-pointer flex items-center gap-2.5 hover:bg-muted/30 transition-colors"
                :class="{ 'bg-primary/10 dark:bg-primary/20 ring-1 ring-primary/40': selectedNoteForUpload === note.id }"
              >
                <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-secondary/40 dark:bg-secondary/20">
                  <FileText class="h-4 w-4" :class="selectedNoteForUpload === note.id ? 'text-primary' : 'text-muted-foreground'" />
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium truncate">{{ note.title }}</p>
                  <p class="text-xs text-muted-foreground">{{ formatDateRelative(note.updated_at || note.created_at) }}</p>
                </div>
              </div>
            </div>
            <p class="text-xs text-muted-foreground mt-1">The file will be attached to the selected note.</p>
          </div>
        </div>
        
        <!-- Footer -->
        <div class="p-4 border-t border-border flex flex-col sm:flex-row gap-3 justify-end">
          <Button variant="outline" type="button" @click="showFileUploadDialog = false" class="w-full sm:w-auto">Cancel</Button>
          <Button
            type="button"
            @click="uploadFile"
            :disabled="!selectedFile || !selectedNoteForUpload || uploadingFile"
            class="w-full sm:w-auto bg-primary hover:bg-primary/90 text-white gap-2"
          >
            <span v-if="uploadingFile" class="flex items-center gap-2">
              <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Uploading...
            </span>
            <span v-else class="flex items-center gap-2">
              <Paperclip class="h-4 w-4" />
              Upload File
            </span>
          </Button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<style>
/* Hide scrollbar but maintain scroll functionality */
.hide-scrollbar {
  scrollbar-width: none; /* Firefox */
  -ms-overflow-style: none; /* IE and Edge */
}

.hide-scrollbar::-webkit-scrollbar {
  display: none; /* Chrome, Safari, Opera */
}

/* Prevent iOS text size adjustment and zooming */
@media screen and (max-width: 767px) {
  html, body {
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
  }
  
  input[type="text"],
  input[type="email"],
  input[type="search"],
  input[type="password"] {
    font-size: 16px; /* Prevents iOS zoom on focus */
  }
  
  /* Improve touch targets */
  button, 
  .button,
  [role="button"],
  a {
    min-height: 44px;
    min-width: 44px;
  }
}
/* Editor Styles */
.editor-container {
  max-width: 100%;
  margin-left: auto;
  margin-right: auto;
  transition-property: color, background-color, border-color;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
  display: flex;
  flex-direction: column;
  flex-grow: 1;
  height: 100%;
}

.editor-container--editing {
  max-width: 100%;
  margin-left: auto;
  margin-right: auto;
  transition-property: color, background-color, border-color;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
  display: flex;
  flex-direction: column;
  flex-grow: 1;
  height: 100%;
}

.editor-container--editing .ProseMirror {
  min-height: 300px;
  height: auto;
  outline: none;
  padding: 0.75rem 1rem;
  font-size: 15px;
  line-height: 1.6;
  color: hsl(var(--foreground));
}

/* Clean Editor Styles - Apple Notes style */
.clean-editor {
  color: hsl(var(--foreground));
}

.clean-editor .ProseMirror {
  height: auto;
  min-height: calc(100vh - 13rem);
  outline: none !important;
  border: none !important;
  box-shadow: none !important;
  padding: 0;
  font-size: 15px;
  line-height: 1.7;
  letter-spacing: -0.011em;
  width: 100%;
  min-width: 0;
  overflow-wrap: break-word;
}

.ProseMirror {
  overflow-y: auto !important;
  height: auto !important;
  max-height: calc(100vh - 10rem) !important;
}

/* Dedicated scrolling containers */
.scroll-container {
  position: relative;
  overflow: auto !important;
  display: flex;
  flex-direction: column;
  height: 100% !important;
}

.editor-content-scroll {
  position: relative;
  flex: 1 1 auto;
  overflow: auto !important;
  min-height: 300px;
  height: auto !important;
  max-height: calc(100vh - 13rem) !important;
}

.editor-content-scroll .ProseMirror {
  overflow-y: auto !important;
  padding: 0.5rem 1rem !important;
  height: auto !important;
}

/* Direct selector for scroll target */
.ProseMirror-focused {
  overflow: auto !important;
}

/* Editor scrolling styles */
.editor-scroll {
  overflow-y: auto !important;
  height: 100% !important;
  max-height: calc(100vh - 12rem) !important;
  display: block;
  width: 100%;
  position: relative;
}

.editor-scroll .ProseMirror {
  min-height: 300px;
  height: auto !important;
  padding: 1rem;
  outline: none !important;
  overflow-y: visible !important;
}

/* Ensure the editor container doesn't interfere with scrolling */
.editor-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 100%;
}

/* Headings styling - more Apple Notes like */
.clean-editor .ProseMirror h1,
.editor-container:not(.editor-container--editing) .ProseMirror h1 {
  font-size: 2rem !important;
  font-weight: 700 !important;
  margin: 1.75rem 0 0.85rem 0 !important;
  line-height: 1.2 !important;
  letter-spacing: -0.01em !important;
  color: hsl(var(--foreground)) !important;
}

.clean-editor .ProseMirror h2,
.editor-container:not(.editor-container--editing) .ProseMirror h2 {
  font-size: 1.65rem !important;
  font-weight: 600 !important;
  margin: 1.4rem 0 0.75rem 0 !important;
  line-height: 1.3 !important;
  letter-spacing: -0.01em !important;
  color: hsl(var(--foreground) / 0.95) !important;
}

.clean-editor .ProseMirror h3,
.editor-container:not(.editor-container--editing) .ProseMirror h3 {
  font-size: 1.35rem !important;
  font-weight: 600 !important;
  margin: 1.2rem 0 0.6rem 0 !important;
  line-height: 1.4 !important;
  color: hsl(var(--foreground) / 0.9) !important;
}

.clean-editor .ProseMirror p {
  margin: 0.7rem 0;
  padding: 0.15rem 0;
  line-height: 1.65;
  letter-spacing: -0.01em;
}

/* Regular lists styling */
.clean-editor .ProseMirror ul,
.clean-editor .ProseMirror ol {
  padding-left: 1.5rem;
  margin: 0.8rem 0;
}

.clean-editor .ProseMirror ul li,
.clean-editor .ProseMirror ol li {
  margin-bottom: 0.5rem;
  padding: 0.15rem 0;
  line-height: 1.6;
}

/* Style for bullet points */
.clean-editor .ProseMirror ul:not([data-type="taskList"]) li::marker {
  color: hsl(var(--primary));
}

/* Style for numbered lists */
.clean-editor .ProseMirror ol li::marker {
  color: hsl(var(--primary)/0.9);
  font-weight: 500;
}

.clean-editor .ProseMirror ul[data-type="taskList"] {
  list-style: none;
  padding: 0;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li {
  display: flex;
  align-items: flex-start;
  margin-bottom: 0.8em;
  position: relative;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li > label {
  flex: 0 0 auto;
  margin-right: 0.75em;
  user-select: none;
  display: flex;
  align-items: center;
  justify-content: center;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li > div {
  flex: 1 1 auto;
  margin-top: 2px;
}

/* Custom checkbox styling */
.clean-editor .ProseMirror ul[data-type="taskList"] li > label > input[type="checkbox"] {
  -webkit-appearance: none;
  appearance: none;
  width: 18px;
  height: 18px;
  border: 1.5px solid hsl(var(--muted-foreground));
  border-radius: 50%;
  margin: 0;
  display: grid;
  place-content: center;
  margin-top: 0.15em;
  cursor: pointer;
  transition: all 0.2s ease;
  background-color: transparent;
  position: relative;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li > label input[type="checkbox"]:hover {
  border-color: hsl(var(--primary)/0.6);
  background-color: hsl(var(--primary)/0.05);
}

.dark .clean-editor .ProseMirror ul[data-type="taskList"] li > label input[type="checkbox"]:hover {
  border-color: hsl(var(--primary)/0.7);
  background-color: hsl(var(--primary)/0.1);
}

.clean-editor .ProseMirror ul[data-type="taskList"] li > label input[type="checkbox"]:checked {
  background-color: hsl(var(--primary));
  border-color: hsl(var(--primary));
  position: relative;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li > label input[type="checkbox"]:checked::after {
  content: '';
  position: absolute;
  left: 6px;
  top: 3px;
  width: 5px;
  height: 9px;
  border: solid white;
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
}

/* Add strikethrough to completed items */
.clean-editor .ProseMirror ul[data-type="taskList"] li > label input[type="checkbox"]:checked ~ div p {
  text-decoration: line-through;
  opacity: 0.6;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li > label input[type="checkbox"]:focus {
  outline: none;
}

.clean-editor .ProseMirror ul[data-type="taskList"] li p {
  margin: 0;
  padding: 0;
}

/* First paragraph after heading has no top margin */
.clean-editor .ProseMirror h1 + p,
.clean-editor .ProseMirror h2 + p,
.clean-editor .ProseMirror h3 + p {
  margin-top: 0;
}

/* Handle dark mode text coloring */
.dark .clean-editor .ProseMirror {
  color: hsl(var(--foreground));
}

/* Desktop Editor Styles */
@media (min-width: 768px) {
  .editor-container--editing .ProseMirror {
    font-size: 15px;
    line-height: 1.6;
    padding: 2rem;
  }

  .editor-container--editing .ProseMirror h1 {
    font-size: 1.75rem;
    margin: 1.5rem 0 1rem;
    font-weight: 600;
    color: hsl(var(--foreground));
    letter-spacing: -0.025em;
  }

  .editor-container--editing .ProseMirror h2 {
    font-size: 1.5rem;
    margin: 1.25rem 0 0.75rem;
    font-weight: 600;
    color: hsl(var(--foreground));
    letter-spacing: -0.025em;
  }

  .editor-container--editing .ProseMirror h3 {
    font-size: 1.25rem;
    margin: 1rem 0 0.5rem;
    font-weight: 600;
    color: hsl(var(--foreground));
    letter-spacing: -0.025em;
  }

  .editor-container--editing .ProseMirror p {
    margin: 0.75rem 0;
    color: hsl(var(--foreground) / 0.9);
  }

  .editor-container--editing .ProseMirror ul,
  .editor-container--editing .ProseMirror ol {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
    color: hsl(var(--foreground) / 0.9);
  }

  .editor-container--editing .ProseMirror li {
    margin: 0.25rem 0;
  }

  .editor-container--editing .ProseMirror blockquote {
    margin: 1rem 0;
    padding: 0.5rem 0 0.5rem 1rem;
    border-left: 4px solid hsl(var(--border));
    color: hsl(var(--muted-foreground));
    font-style: italic;
  }

  .editor-container--editing .ProseMirror pre {
    margin: 1rem 0;
    padding: 1rem;
    background-color: hsl(var(--muted) / 0.5);
    border-radius: 0.375rem;
    font-size: 0.875rem;
    line-height: 1.5;
    overflow-x: auto;
  }

  .editor-container--editing .ProseMirror code {
    font-size: 0.875rem;
    padding: 0.125rem 0.25rem;
    background-color: hsl(var(--muted) / 0.3);
    border-radius: 0.25rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  }

  .editor-container--editing .ProseMirror img {
    max-width: 100%;
    height: auto;
    margin: 1rem 0;
    border-radius: 0.375rem;
  }

  .editor-container--editing .ProseMirror a {
    color: hsl(var(--primary));
    text-decoration: underline;
    text-underline-offset: 2px;
    transition: color 0.2s;
  }

  .editor-container--editing .ProseMirror a:hover {
    color: hsl(var(--primary) / 0.8);
  }

  .editor-container--editing .ProseMirror mark {
    background-color: #fef08a;
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
  }

  .dark .editor-container--editing .ProseMirror mark {
    background-color: #854d0e;
  }

  .editor-container--editing .ProseMirror ::selection {
    background-color: hsl(var(--primary) / 0.2);
  }

  /* Task list styles */
  .editor-container--editing .task-list {
    list-style: none;
    padding-left: 0;
    margin: 0.75rem 0;
  }

  .editor-container--editing .task-list li {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    margin: 0.25rem 0;
    padding: 0.25rem 0;
  }

  .editor-container--editing .task-list li > label {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    height: 1.25rem;
    border: 1.5px solid hsl(var(--border));
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
    margin: 0.125rem 0 0 0;
    background: transparent;
    position: relative;
  }

  .editor-container--editing .task-list li > label:hover {
    border-color: hsl(var(--primary));
    background: hsl(var(--primary) / 0.05);
  }

  .dark .editor-container--editing .task-list li > label:hover {
    border-color: hsl(var(--primary) / 0.7);
    background: hsl(var(--primary) / 0.1);
  }

  .editor-container--editing .task-list li > label input[type="checkbox"] {
    display: none;
  }

  .editor-container--editing .task-list li > label input[type="checkbox"]:checked + span {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: hsl(var(--primary));
  }

  .editor-container--editing .task-list li > label input[type="checkbox"]:checked + span::before {
    content: "";
    width: 0.5rem;
    height: 0.5rem;
    background-color: hsl(var(--primary));
    border-radius: 50%;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
  }

  .editor-container--editing .task-list li > div {
    flex: 1;
    min-width: 0;
    line-height: 1.5;
    padding-top: 0.125rem;
  }

  /* Dark mode adjustments */
  .dark .editor-container--editing .task-list li > label {
    border-color: hsl(var(--border));
  }

  .dark .editor-container--editing .task-list li > label:hover {
    border-color: hsl(var(--primary));
    background: hsl(var(--primary) / 0.1);
  }

  /* Mobile adjustments */
  @media (max-width: 768px) {
    .editor-container--editing .task-list li > label {
      width: 1.375rem;
      height: 1.375rem;
    }
    
    .editor-container--editing .task-list li > label input[type="checkbox"]:checked + span::before {
      width: 0.625rem;
      height: 0.625rem;
    }
  }

  .md\:relative {
    position: relative;
    transform: none !important;
  }

  .md\:translate-x-0 {
    transform: none !important;
  }
}

/* Mobile Editor Styles */
@media (max-width: 768px) {
  .editor-container {
    padding: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
  }

  .editor-container--editing {
    padding: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
  }

  /* Enhanced mobile editor styles */
  .editor-container--editing .ProseMirror {
    min-height: 300px;
    height: auto;
    padding: 1rem;
    font-size: 16px;
    line-height: 1.6;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    word-wrap: break-word;
    word-break: normal;
    overflow-wrap: break-word;
    -webkit-text-size-adjust: 100%;
    -webkit-tap-highlight-color: transparent;
    caret-color: hsl(var(--primary));
  }
  
  /* Ensure proper scrolling and container width on mobile */
  .editor-scroll {
    max-height: calc(100vh - 10rem) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    width: 100% !important;
    -webkit-overflow-scrolling: touch;
  }

  .editor-container--editing .ProseMirror:focus {
    outline: none;
  }

  .editor-container--editing .ProseMirror p {
    margin: 0.75rem 0;
    min-height: 1.5em;
    width: 100%;
    max-width: 100%;
    white-space: normal;
    overflow-wrap: break-word;
    word-break: normal;
  }

  .editor-container--editing .ProseMirror h1 {
    font-size: 1.5rem;
    margin: 1.25rem 0 0.75rem;
  }

  .editor-container--editing .ProseMirror h2 {
    font-size: 1.25rem;
    margin: 1rem 0 0.5rem;
  }

  .editor-container--editing .ProseMirror h3 {
    font-size: 1.1rem;
    margin: 0.75rem 0 0.5rem;
  }

  .editor-container--editing .ProseMirror ul,
  .editor-container--editing .ProseMirror ol {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
  }

  .editor-container--editing .ProseMirror li {
    margin: 0.25rem 0;
    padding-left: 0.25rem;
  }

  .editor-container--editing .ProseMirror blockquote {
    margin: 1rem 0;
    padding: 0.5rem 0 0.5rem 1rem;
    border-left: 4px solid hsl(var(--border));
  }

  .editor-container--editing .ProseMirror pre {
    margin: 1rem 0;
    padding: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.5;
    background-color: hsl(var(--muted) / 0.5);
    border-radius: 0.375rem;
    overflow-x: auto;
  }

  .editor-container--editing .ProseMirror code {
    font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
    font-size: 0.875rem;
    padding: 0.125rem 0.25rem;
    background-color: hsl(var(--muted) / 0.7);
    border-radius: 0.25rem;
  }
  
  /* Mobile styles for clean editor */
  .clean-editor .ProseMirror {
    width: 100%;
    min-width: 0;
    max-width: 100vw;
    font-size: 15px;
    overflow-wrap: break-word;
    word-break: normal;
    box-sizing: border-box;
  }
  
  /* Handle code blocks and preformatted text on mobile */
  .clean-editor .ProseMirror pre {
    overflow-x: auto;
    white-space: pre;
    max-width: 100%;
    border-radius: 4px;
    padding: 0.75rem;
    font-size: 0.875rem;
    background-color: hsl(var(--muted));
    margin: 0.75rem 0;
  }
  
  .clean-editor .ProseMirror code {
    font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
    font-size: 0.9em;
  }
  
  /* Better image handling */
  .clean-editor .ProseMirror img {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0.75rem 0;
  }
  
  /* Only prevent text wrapping in specific elements that need it */
  .clean-editor .ProseMirror table,
  .clean-editor .ProseMirror pre {
    white-space: pre;
    overflow-x: auto;
  }
  
  /* Ensure proper text wrapping in standard text */
  .clean-editor .ProseMirror p,
  .clean-editor .ProseMirror li,
  .clean-editor .ProseMirror h1,
  .clean-editor .ProseMirror h2,
  .clean-editor .ProseMirror h3,
  .clean-editor .ProseMirror blockquote {
    white-space: normal;
    overflow-wrap: break-word;
    word-break: normal;
    width: 100%;
  }

  .editor-container--editing .ProseMirror img {
    max-width: 100%;
    height: auto;
    border-radius: 0.375rem;
  }

  .editor-container--editing .ProseMirror a {
    color: hsl(var(--primary));
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .editor-container--editing .ProseMirror mark {
    background-color: #fef08a;
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
  }

  .dark .editor-container--editing .ProseMirror mark {
    background-color: #854d0e;
  }

  .editor-container--editing .ProseMirror ::selection {
    background-color: hsl(var(--primary) / 0.2);
  }

  /* Task list styles */
  .editor-container--editing .task-list {
    list-style: none;
    padding-left: 0;
  }

  .editor-container--editing .task-list li {
    display: flex;
    gap: 0.5rem;
    align-items: flex-start;
    margin: 0.5rem 0;
    padding: 0.25rem 0;
  }

  .editor-container--editing .task-list li > label {
    margin-top: 0.25rem;
    flex-shrink: 0;
    width: 1.25rem;
    height: 1.25rem;
  }

  .editor-container--editing .task-list li > div {
    flex: 1;
    min-width: 0;
  }

  /* Placeholder */
  .editor-container--editing .ProseMirror p.is-editor-empty:first-child::before {
    color: var(--muted-foreground);
    content: attr(data-placeholder);
    float: left;
    pointer-events: none;
    height: 0;
  }

  /* Improve touch targets on mobile */
  button,
  .button {
    min-height: 44px;
    min-width: 44px;
  }

  /* Improve spacing on mobile */
  .p-4 {
    padding: 1rem;
  }

  /* Make text more readable on mobile */
  .text-sm {
    font-size: 0.9375rem;
  }

  /* Improve dialog usability on mobile */
  .dialog-content {
    margin: 1rem;
    max-height: calc(100vh - 2rem);
  }

  /* Hide scrollbar but keep functionality */
  .scrollbar-hide {
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none;  /* Firefox */
  }
  .scrollbar-hide::-webkit-scrollbar {
    display: none;  /* Chrome, Safari and Opera */
  }

  /* Improve toolbar on mobile */
  .toolbar-scroll {
    -webkit-overflow-scrolling: touch;
    scroll-snap-type: x mandatory;
  }

  .toolbar-scroll > * {
    scroll-snap-align: start;
  }
}

/* Ensure the main content area has proper spacing */
.flex-1 {
  position: relative;
  z-index: 30;
}

/* Ensure proper stacking context */
.z-40 {
  z-index: 40;
}

.z-30 {
  z-index: 30;
}
/* Custom scrollbar styles */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(115, 115, 115, 0.3);
  border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: rgba(115, 115, 115, 0.5);
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(115, 115, 115, 0.2);
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: rgba(115, 115, 115, 0.4);
}

.transitions-enabled * {
  transition-property: color, background-color, border-color, text-decoration-color, fill, stroke, opacity, box-shadow, transform, filter, backdrop-filter;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 200ms;
}
/* Apple Notes-style Task List Styling */
ul.apple-notes-task-list {
  list-style: none;
  padding: 0;
}

li.apple-notes-task-item {
  display: flex;
  margin-bottom: 0.5em;
}

li.apple-notes-task-item label {
  display: flex;
  align-items: flex-start;
  flex: 0 0 auto;
  margin-right: 0.5em;
  cursor: pointer;
  margin-top: 0.2rem !important;
}

li.apple-notes-task-item > label > input[type="checkbox"] {
  -webkit-appearance: none !important;
  appearance: none !important;
  width: 20px !important;
  height: 20px !important;
  border: 2px solid #d1d5db !important;
  border-radius: 50% !important;
  margin: 0 !important;
  padding: 0 !important;
  cursor: pointer !important;
  transition: all 0.2s ease !important;
  outline: none !important;
  position: relative !important;
}

.dark li.apple-notes-task-item > label > input[type="checkbox"] {
  border-color: #4b5563 !important;
}

li.apple-notes-task-item > label > input[type="checkbox"]:hover {
  border-color: hsl(var(--primary)/0.7) !important;
  background-color: hsl(var(--primary)/0.05) !important;
}

.dark li.apple-notes-task-item > label > input[type="checkbox"]:hover {
  border-color: hsl(var(--primary)/0.8) !important;
  background-color: hsl(var(--primary)/0.1) !important;
}

li.apple-notes-task-item > label > input[type="checkbox"]:checked {
  background-color: hsl(var(--primary)) !important;
  border-color: hsl(var(--primary)) !important;
}

li.apple-notes-task-item > label > input[type="checkbox"]:checked::after {
  content: '' !important;
  position: absolute !important;
  left: 6px !important;
  top: 3px !important;
  width: 5px !important;
  height: 9px !important;
  border: solid white !important;
  border-width: 0 2px 2px 0 !important;
  transform: rotate(45deg) !important;
}

li.apple-notes-task-item > label > input[type="checkbox"]:checked ~ div p {
  text-decoration: line-through !important;
  opacity: 0.6 !important;
}

li.apple-notes-task-item > div {
  flex: 1 !important;
  min-width: 0 !important;
  margin-top: 0.15rem !important;
}

li.apple-notes-task-item > div > p {
  margin: 0 !important;
  padding: 0 !important;
}
/* Beautiful blockquote styling */
.clean-editor .ProseMirror blockquote {
  border-left: 3px solid hsl(var(--primary)/0.6);
  padding-left: 1rem;
  margin: 1.2rem 0 1.2rem 0.5rem;
  font-style: italic;
  color: hsl(var(--foreground)/0.85);
  background-color: hsl(var(--muted)/0.3);
  border-radius: 0.25rem;
  padding: 0.75rem 1rem 0.75rem 1.25rem;
}

.dark .clean-editor .ProseMirror blockquote {
  background-color: hsl(var(--muted)/0.2);
  border-left-color: hsl(var(--primary)/0.5);
}

/* Code block styling */
.clean-editor .ProseMirror pre {
  background-color: hsl(var(--muted)/0.7);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
  margin: 1rem 0;
  overflow-x: auto;
  font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
  font-size: 0.85em;
  line-height: 1.5;
  border: 1px solid hsl(var(--border)/0.5);
}

.dark .clean-editor .ProseMirror pre {
  background-color: hsl(var(--muted)/0.4);
  border-color: hsl(var(--border)/0.4);
}

/* Inline code styling */
.clean-editor .ProseMirror code {
  background-color: hsl(var(--muted)/0.5);
  border-radius: 0.25rem;
  padding: 0.1rem 0.4rem;
  font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
  font-size: 0.9em;
  border: 1px solid hsl(var(--border)/0.3);
}

.dark .clean-editor .ProseMirror code {
  background-color: hsl(var(--muted)/0.3);
  border-color: hsl(var(--border)/0.2);
}

/* Highlight styling */
.clean-editor .ProseMirror mark {
  background-color: hsl(50, 100%, 80%);
  padding: 0 0.2rem;
  border-radius: 0.15rem;
}

.dark .clean-editor .ProseMirror mark {
  background-color: hsl(50, 70%, 45%);
  color: hsl(var(--background));
}

/* Links styling */
.clean-editor .ProseMirror a {
  color: hsl(var(--primary));
  text-decoration: underline;
  text-decoration-thickness: 1px;
  text-underline-offset: 0.15em;
  transition: all 0.2s ease;
}

.clean-editor .ProseMirror a:hover {
  color: hsl(var(--primary)/0.8);
  text-decoration-thickness: 2px;
}

/* Special paragraph spacing for better reading */
.clean-editor .ProseMirror h1 + p,
.clean-editor .ProseMirror h2 + p,
.clean-editor .ProseMirror h3 + p {
  margin-top: 0.5rem;
}

/* Enhanced styling for non-edit (read) mode */
.editor-container:not(.editor-container--editing) .ProseMirror {
  padding: 1rem 2rem;
  background: linear-gradient(to bottom, transparent, hsl(var(--background)/0.6) 20px, hsl(var(--background)) 40px);
  border-radius: 0.75rem;
  /* Shadow removed */
}

.dark .editor-container:not(.editor-container--editing) .ProseMirror {
  /* Shadow removed */
}

/* Add a subtle background to the entire container in read mode */
.editor-container:not(.editor-container--editing) {
  background-color: hsl(var(--background)/0.5);
  border-radius: 0.75rem;
  /* Shadow removed */
  margin: 0.5rem;
  transition: all 0.3s ease;
}

/* Enhance appearance of headers even more in non-edit mode */
.editor-container:not(.editor-container--editing) .ProseMirror h1 {
  font-size: 2.1rem !important;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid hsl(var(--border)/0.2);
  margin-bottom: 1.25rem !important;
}

/* Improve the appearance of all text in non-edit mode */
.editor-container:not(.editor-container--editing) .ProseMirror p,
.editor-container:not(.editor-container--editing) .ProseMirror li,
.editor-container:not(.editor-container--editing) .ProseMirror blockquote {
  font-size: 1.05rem;
  line-height: 1.7;
}
</style>
