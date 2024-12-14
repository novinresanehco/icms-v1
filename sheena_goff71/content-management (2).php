<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\FileUploadService;

class ContentController extends Controller
{
    protected FileUploadService $fileService;
    
    public function __construct(FileUploadService $fileService)
    {
        $this->fileService = $fileService;
        $this->middleware('auth');
    }

    // ایجاد محتوای جدید با حداقل پردازش
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
            'category_id' => 'required|exists:categories,id',
            'image' => 'nullable|image|max:2048'
        ]);

        DB::beginTransaction();
        try {
            $content = new Content($validated);
            
            if ($request->hasFile('image')) {
                $content->image = $this->fileService->upload(
                    $request->file('image'),
                    'contents'
                );
            }
            
            $content->user_id = auth()->id();
            $content->save();

            // کش را پاک می‌کنیم
            Cache::tags(['contents'])->flush();
            
            DB::commit();
            return redirect()->route('contents.index');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // نمایش محتوا با کش‌گذاری ساده
    public function index()
    {
        $contents = Cache::tags(['contents'])->remember('contents.all', 3600, function() {
            return Content::with(['category', 'user'])
                         ->latest()
                         ->paginate(20);
        });

        return view('contents.index', compact('contents'));
    }

    // جستجوی سریع 
    public function search(Request $request)
    {
        $query = $request->get('q');
        
        $contents = Content::where('title', 'like', "%{$query}%")
                          ->orWhere('content', 'like', "%{$query}%")
                          ->latest()
                          ->paginate(20);

        return view('contents.index', compact('contents'));
    }

    // آپدیت سریع
    public function update(Request $request, Content $content)
    {
        $this->authorize('update', $content);

        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
            'category_id' => 'required|exists:categories,id',
            'image' => 'nullable|image|max:2048'
        ]);

        DB::beginTransaction();
        try {
            if ($request->hasFile('image')) {
                // حذف تصویر قبلی
                $this->fileService->delete($content->image);
                
                $validated['image'] = $this->fileService->upload(
                    $request->file('image'),
                    'contents'
                );
            }

            $content->update($validated);
            Cache::tags(['contents'])->flush();
            
            DB::commit();
            return redirect()->route('contents.show', $content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // حذف سریع
    public function destroy(Content $content)
    {
        $this->authorize('delete', $content);

        DB::transaction(function() use ($content) {
            if ($content->image) {
                $this->fileService->delete($content->image);
            }
            $content->delete();
            Cache::tags(['contents'])->flush();
        });

        return redirect()->route('contents.index');
    }
}
