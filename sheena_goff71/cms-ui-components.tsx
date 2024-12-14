import React, { useState, useEffect } from 'react';

const ContentSection = ({ content, layout = 'default' }) => {
  const [processedContent, setProcessedContent] = useState(null);

  useEffect(() => {
    const processContent = async () => {
      try {
        const response = await fetch('/api/content/process', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ content, layout })
        });
        
        const result = await response.json();
        setProcessedContent(result.content);
      } catch (err) {
        setProcessedContent(null);
      }
    };

    processContent();
  }, [content, layout]);

  if (!processedContent) {
    return (
      <div className="w-full h-32 flex items-center justify-center">
        <div className="w-8 h-8 border-t-2 border-blue-500 rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className={`content-section ${layout} w-full`}>
      <div className="content-wrapper p-4" dangerouslySetInnerHTML={{ __html: processedContent }} />
    </div>
  );
};

const MediaGallery = ({ items = [], columns = 3, onSelect }) => {
  const gridClass = `grid grid-cols-1 md:grid-cols-${Math.min(6, Math.max(1, columns))} gap-4`;

  return (
    <div className={gridClass}>
      {items.map((item, index) => (
        <div 
          key={item.id || index}
          className="relative group overflow-hidden rounded-lg bg-gray-100"
          onClick={() => onSelect && onSelect(item)}
        >
          <div className="aspect-w-16 aspect-h-9">
            {item.type === 'image' ? (
              <img 
                src={item.url} 
                alt={item.alt || ''}
                className="w-full h-full object-cover transition-transform group-hover:scale-105"
              />
            ) : (
              <div className="w-full h-full flex items-center justify-center bg-gray-200">
                <span className="text-gray-500">{item.type}</span>
              </div>
            )}
          </div>
          
          {item.caption && (
            <div className="absolute bottom-0 w-full bg-gradient-to-t from-black/50 to-transparent p-2">
              <p className="text-sm text-white">{item.caption}</p>
            </div>
          )}
        </div>
      ))}
    </div>
  );
};

const TemplateLayout = ({ template, sections = [], media = [] }) => {
  return (
    <div className="template-layout w-full">
      <div className="sections space-y-6">
        {sections.map((section, index) => (
          <ContentSection 
            key={section.id || index}
            content={section.content}
            layout={section.layout}
          />
        ))}
      </div>

      {media.length > 0 && (
        <div className="media-section mt-8">
          <MediaGallery 
            items={media}
            columns={3}
          />
        </div>
      )}
    </div>
  );
};

export default {
  ContentSection,
  MediaGallery,
  TemplateLayout
};
