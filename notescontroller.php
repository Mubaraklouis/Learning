<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NotesController extends Controller
{
    /*
    |--------------------------------
    | Notes functionality
    |--------------------------------
    | All the controller for notes functionality
    |
    |*/

    public function index()
    {
        //send the uuid to the notes api service
        $uuuid = Auth::user()->uuid;
        // get the folders from the notes api service
        $folders = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/folders',[
            'user_uuid' => $uuuid
        ]);

        if ($folders->successful()) {
            $folders = $folders->json();
        } else {
            $folders = [];
        }

        return Inertia::render('notes/index', ['folders' => $folders]);
    }

    public function store(Request $request)
    {
        $response = Http::post('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/notes', [
            'title' => $request->title,
            'content' => $request->content,
            'folder_id' => $request->folder_id
        ]);

        if ($response->successful()) {
            // Get updated folders data
            $folders = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/folders');
            if ($folders->successful()) {
                $folders = $folders->json();
            } else {
                $folders = [];
            }

            return redirect()->route('notes');
        }

        return back()->with('error', 'Failed to create note');
    }

    public function update(Request $request,$id)
    {
        $response = Http::put('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/notes/'.$id, [
            'title' => $request->title,
            'content' => $request->content,
            'folder_id' => $request->folder_id
        ]);

        if ($response->successful()) {

            // Get updated folders data
            $folders = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/folders');
            if ($folders->successful()) {
                $folders = $folders->json();
            } else {
                $folders = [];
            }

            return redirect()->route('notes')->with('folders', $folders);
        }

        return back()->with('error', 'Failed to create note');
    }

    public function destroy($id)
    {
        $response = Http::delete("http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/notes/{$id}");

        if ($response->successful()) {
            // Get updated folders data
            $folders = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/folders');
            if ($folders->successful()) {
                $folders = $folders->json();
            } else {
                $folders = [];
            }

            return redirect()->route('notes')->with('folders', $folders);
        }

        return back()->with('error', 'Failed to delete note');
    }


    public function uploadNoteFiles(Request $request, $id)
    {
        dd($request->all());
        // Validate request
        $validated = $request->validate([
            'file' => 'required|file|max:10240', // Max 10MB file
            'note_id' => 'sometimes|string', // Optional note_id from request body
        ]);

        // Use note_id from request body if provided, otherwise use route parameter
        $noteId = $request->input('note_id', $id);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filename = 'note-' . $noteId . '-' . time() . '.' . $file->getClientOriginalExtension();

            // Upload to S3 and get the URL
            $path = Storage::disk('s3')->put('notes/' . $filename, file_get_contents($file), 'public');
            $fileUrl = config('filesystems.disks.s3.url') . '/notes/' . $filename;

            // Get file info
            $fileInfo = [
                'id' => Str::uuid(),
                'name' => $file->getClientOriginalName(),
                'type' => $file->getMimeType(),
                'url' => $fileUrl,
                'size' => $file->getSize(),
                'created_at' => now()->toISOString()
            ];

            // Get the current note
            $note = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/notes/' . $noteId);
            if ($note->successful()) {
                $noteData = $note->json();
                $attachments = $noteData['attachments'] ?? [];
                $attachments[] = $fileInfo;

                // Update the note with the new attachment
                $response = Http::put('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/notes/' . $noteId, [
                    'title' => $noteData['title'],
                    'content' => $noteData['content'],
                    'folder_id' => $noteData['folder_id'],
                    'attachments' => $attachments
                ]);

                if ($response->successful()) {
                    // Get updated note data
                    $updatedNote = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/notes/' . $noteId);
                    $noteData = $updatedNote->json();

                    // For AJAX requests, return JSON
                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => true,
                            'message' => 'File uploaded successfully',
                            'note' => $noteData,
                            'file' => $fileInfo
                        ]);
                    }

                    // For regular requests, redirect with success message
                    $folders = Http::get('http://ec2-3-80-26-50.compute-1.amazonaws.com/api/v1/folders');
                    if ($folders->successful()) {
                        $folders = $folders->json();
                    } else {
                        $folders = [];
                    }

                    return redirect()->route('notes')->with([
                        'folders' => $folders,
                        'success' => 'File uploaded successfully'
                    ]);
                }
            }
        }

        return back()->with('error', 'Failed to upload file');
    }
}
