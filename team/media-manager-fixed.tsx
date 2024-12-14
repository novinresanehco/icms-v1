import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Image, Film, FileText, Folder, Upload, Grid, List } from 'lucide-react';

const MediaManager = () => {
  const [viewMode, setViewMode] = React.useState('grid');
  const [selectedType, setSelectedType] = React.useState('all');

  const mediaItems = [
    { id: 1, type: 'image', name: 'hero-banner.jpg', size: '2.4 MB', date: '1402/12/10' },
    { id: 2, type: 'video', name: 'product-demo.mp4', size: '15.8 MB', date: '1402/12/09' },
    { id: 3, type: 'document', name: 'report.pdf', size: '1.2 MB', date: '1402/12/08' },
    { id: 4, type: 'image', name: 'team-photo.jpg', size: '3.1 MB', date: '1402/12/07' },
    { id: 5, type: 'image', name: 'office.jpg', size: '2.8 MB', date: '1402/12/07' },
    { id: 6, type: 'video', name: 'tutorial.mp4', size: '8.5 MB', date: '1402/12/06' }
  ];

  const filteredItems = selectedType === 'all' 
    ? mediaItems 
    : mediaItems.filter(item => item.type === selectedType);

  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>مدیریت رسانه</CardTitle>
            <button className="flex items-center px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
              <Upload className="w-4 h-4 mr-2" />
              آپلود فایل
            </button>
          </div>
        </CardHeader>

        <CardContent className="p-6">
          <div className="flex flex-col lg:flex-row gap-6">
            {/* Sidebar */}
            <div className="lg:w-48">
              <div className="flex lg:flex-col gap-2">
                <button 
                  onClick={() => setSelectedType('all')}
                  className={`flex items-center p-2 rounded w-full ${
                    selectedType === 'all' ? 'bg-blue-50 text-blue-600' : 'hover:bg-gray-50'
                  }`}
                >
                  <Folder className="w-4 h-4 mr-2" />
                  <span>همه فایل‌ها</span>
                </button>
                <button 
                  onClick={() => setSelectedType('image')}
                  className={`flex items-center p-2 rounded w-full ${
                    selectedType === 'image' ? 'bg-blue-50 text-blue-600' : 'hover:bg-gray-50'
                  }`}
                >
                  <Image className="w-4 h-4 mr-2" />
                  <span>تصاویر</span>
                </button>
                <button 
                  onClick={() => setSelectedType('video')}
                  className={`flex items-center p-2 rounded w-full ${
                    selectedType === 'video' ? 'bg-blue-50 text-blue-600' : 'hover:bg-gray-50'
                  }`}
                >
                  <Film className="w-4 h-4 mr-2" />
                  <span>ویدیوها</span>
                </button>
                <button 
                  onClick={() => setSelectedType('document')}
                  className={`flex items-center p-2 rounded w-full ${
                    selectedType === 'document' ? 'bg-blue-50 text-blue-600' : 'hover:bg-gray-50'
                  }`}
                >
                  <FileText className="w-4 h-4 mr-2" />
                  <span>اسناد</span>
                </button>
              </div>
            </div>

            {/* Main Content */}
            <div className="flex-1">
              {/* View Toggle */}
              <div className="flex justify-end mb-4">
                <div className="flex bg-gray-100 rounded p-1">
                  <button 
                    onClick={() => setViewMode('grid')}
                    className={`p-2 rounded ${viewMode === 'grid' ? 'bg-white shadow' : ''}`}
                  >
                    <Grid className="w-4 h-4" />
                  </button>
                  <button 
                    onClick={() => setViewMode('list')}
                    className={`p-2 rounded ${viewMode === 'list' ? 'bg-white shadow' : ''}`}
                  >
                    <List className="w-4 h-4" />
                  </button>
                </div>
              </div>

              {/* Files Display */}
              {viewMode === 'grid' ? (
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                  {filteredItems.map(item => (
                    <Card key={item.id} className="hover:shadow-lg transition-shadow">
                      <div className="aspect-video bg-gray-100 flex items-center justify-center">
                        {item.type === 'image' && (
                          <img
                            src="/api/placeholder/400/320"
                            alt={item.name}
                            className="w-full h-full object-cover"
                          />
                        )}
                        {item.type === 'video' && <Film className="w-12 h-12 text-gray-400" />}
                        {item.type === 'document' && <FileText className="w-12 h-12 text-gray-400" />}
                      </div>
                      <CardContent className="p-3">
                        <div className="text-sm font-medium truncate">{item.name}</div>
                        <div className="text-xs text-gray-500 mt-1">{item.size}</div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              ) : (
                <div className="divide-y">
                  {filteredItems.map(item => (
                    <div key={item.id} className="flex items-center py-3 hover:bg-gray-50 px-2 rounded">
                      <div className="w-8 mr-3">
                        {item.type === 'image' && <Image className="w-5 h-5 text-blue-500" />}
                        {item.type === 'video' && <Film className="w-5 h-5 text-purple-500" />}
                        {item.type === 'document' && <FileText className="w-5 h-5 text-green-500" />}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="text-sm font-medium truncate">{item.name}</div>
                        <div className="text-xs text-gray-500">{item.size}</div>
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
