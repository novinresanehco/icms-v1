import React, { useState } from 'react';

const TemplateDisplay = ({ templateId, data = {} }) => {
  const [content, setContent] = useState(null);
  const [loading, setLoading] = useState(true);

  const loadTemplate = async () => {
    try {
      setLoading(true);
      const response = await fetch(`/api/templates/${templateId}/render`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      
      if (!response.ok) throw new Error('Failed to load template');
      
      const result = await response.json();
      setContent(result.content);
    } catch (error) {
      console.error(error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="w-full h-32 flex items-center justify-center">
        <div className="w-8 h-8 border-t-2 border-gray-900 rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className="template-container w-full">
      <div className="template-content" dangerouslySetInnerHTML={{ __html: content }} />
    </div>
  );
};

const MediaGrid = ({ items = [], cols = 3 }) => {
  return (
    <div className={`grid grid-cols-1 md:grid-cols-${cols} gap-4`}>
      {items.map((item, index) => (
        <div key={index} className="relative overflow-hidden rounded-lg bg-gray-100">
          <div className="aspect-w-16 aspect-h-9">
            <img 
              src={item.url}
              alt={item.alt || ''}
              className="w-full h-full object-cover"
            />
          </div>
          {item.caption && (
            <div className="absolute bottom-0 w-full bg-black bg-opacity-50 p-2">
              <p className="text-sm text-white">{item.caption}</p>
            </div>
          )}
        </div>
      ))}
    </div>
  );
};

export default { TemplateDisplay, MediaGrid };
