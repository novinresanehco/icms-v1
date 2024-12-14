import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Image, Film, FileText, Folder, Upload, Grid, List } from 'lucide-react';

const MediaManager = () => {
  const [viewMode, setViewMode] = useState('grid');
  
  const mediaItems = [
    { id: 1, type: 'image', name: 'hero-banner.jpg', size: '2.4 MB', date: '2024-03-10' },
    { id: 2, type: 'video', name: 'product-demo.mp4', size: '15.8 MB', date: '2024-03-09' },
    { id: 3, type: 'document', name: 'report.pdf', size: '1.2 MB', date: '2024-03-08' },
    { id: 4, type: 'image', name: 'team-photo.jpg', size: '3.1 MB', date: '2024-03-07' },
    { id: 5, type: 'image', name: 'office.jpg', size: '2.8 MB', date: '2024-03-07' },
    { id: 6, type: 'video', name: 'tutorial.mp4', size: '8.5 MB', date: '2024-03-06' }
  ];

  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>Media Library</CardTitle>
            <div className="flex space-x-4">
              <button className="flex items-center px-4 py-2 bg-blue-500 text-white rounded">
                <Upload className="w-4 h-4 mr-2" />
                Upload Files
              </button>
              <div className="flex space-x-2 bg-gray-100 rounded p-1">
                <button 
                  className={`p-2 rounded ${viewMode === 'grid' ? 'bg-white shadow' : ''}`}
                  onClick={() => setViewMode('grid')}
                >
                  <Grid className="w-4 h-4" />
                </button>
                <button 
                  className={`p-2 rounded ${viewMode === 'list' ? 'bg-white shadow' : ''}`}
                  onClick={() => setViewMode('list')}
                >
                  <List className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </CardHeader>
        
        <CardContent>
          <div className="flex gap-4">
            {/* Sidebar */}
            <div className="w-48 border-r pr-4">
              <div className="space-y-2">
                <button className="w-full p-2 flex items-center space-x-2 bg-blue-50 text-blue-600 rounded">
                  <Folder className="w-4 h-4" />
                  <span>All Media</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-50 rounded">
                  <Image className="w-4 h-4" />
                  <span>Images</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-50 rounded">
                  <Film className="w-4 h-4" />
                  <span>Videos</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-50 rounded">
                  <FileText className="w-4 h-4" />
                  <span>Documents</span>
                </button>
              </div>
            </div>

            {/* Main Content */}
            <div className="flex-1">
              {viewMode === 'grid' ? (
                <div className="grid grid-cols-4 gap-4">
                  {mediaItems.map(item => (
                    <div key={item.id} className="border rounded-lg overflow-hidden hover:shadow-lg transition-shadow">
                      <div className="aspect-square bg-gray-100 flex items-center justify-center">
                        {item.type === 'image' && <Image className="w-12 h-12 text-gray-400" />}
                        {item.type === 'video' && <Film className="w-12 h-12 text-gray-400" />}
                        {item.type === 'document' && <FileText className="w-12 h-12 text-gray-400" />}
                      </div>
                      <div className="p-2">
                        <div className="text-sm font-medium truncate">{item.name}</div>
                        <div className="text-xs text-gray-500">{item.size}</div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="space-y-2">
                  {mediaItems.map(item => (
                    <div key={item.id} className="flex items-center p-2 hover:bg-gray-50 rounded">
                      {item.type === 'image' && <Image className="w-5 h-5 text-gray-400 mr-3" />}
                      {item.type === 'video' && <Film className="w-5 h-5 text-gray-400 mr-3" />}
                      {item.type === 'document' && <FileText className="w-5 h-5 text-gray-400 mr-3" />}
                      <div className="flex-1">
                        <div className="font-medium">{item.name}</div>
                        <div className="text-sm text-gray-500">{item.size}</div>
                      </div>
                      <div className="text-sm text-gray-500">{item.date}</div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default MediaManager;
