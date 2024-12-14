import React, { useState, useEffect } from 'react';
import { Alert } from 'lucide-react';

const ContentDisplay = ({ content, layout, permissions = [] }) => {
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [renderedContent, setRenderedContent] = useState(null);

  useEffect(() => {
    const renderContent = async () => {
      try {
        setIsLoading(true);
        
        // Validate permissions first
        if (!validatePermissions(permissions)) {
          throw new Error('Insufficient permissions');
        }
        
        // Process content according to layout
        const processed = await processContent(content, layout);
        
        setRenderedContent(processed);
        setError(null);
      } catch (err) {
        setError(err.message);
        setRenderedContent(null);
      } finally {
        setIsLoading(false);
      }
    };
    
    renderContent();
  }, [content, layout, permissions]);

  if (isLoading) {
    return (
      <div className="w-full h-full flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="w-full p-4">
        <Alert className="w-full bg-red-50 border border-red-200 rounded p-4">
          <div className="flex items-center space-x-2 text-red-800">
            <Alert size={20} />
            <p className="font-medium">Error rendering content: {error}</p>
          </div>
        </Alert>
      </div>
    );
  }

  return (
    <div className="w-full">
      <div className={`content-display ${layout} p-4`}>
        {renderedContent}
      </div>
    </div>
  );
};

const GalleryDisplay = ({ items = [], columns = 3 }) => {
  const gridClass = `grid grid-cols-1 md:grid-cols-${columns} gap-4`;
  
  return (
    <div className={gridClass}>
      {items.map((item, index) => (
        <div key={index} className="relative overflow-hidden rounded-lg bg-gray-100">
          {item.type === 'image' ? (
            <img 
              src={item.url} 
              alt={item.alt}
              className="w-full h-48 object-cover"
            />
          ) : (
            <div className="w-full h-48 flex items-center justify-center">
              {item.content}
            </div>
          )}
          {item.caption && (
            <div className="absolute bottom-0 w-full bg-black bg-opacity-50 text-white p-2">
              <p className="text-sm">{item.caption}</p>
            </div>
          )}
        </div>
      ))}
    </div>
  );
};

export { ContentDisplay, GalleryDisplay };

const validatePermissions = (permissions) => {
  // Implementation should check against user's actual permissions
  return true;
};

const processContent = async (content, layout) => {
  // Implementation should handle content processing based on layout
  return content;
};

export default {
  ContentDisplay,
  GalleryDisplay,
};
