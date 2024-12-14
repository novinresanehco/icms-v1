import { useState } from 'react';

const ContentDisplay = ({ content, layout = 'default' }) => {
  return (
    <div className="content-display">
      <div className={`layout-${layout} p-4`}>
        <div className="prose max-w-none" 
             dangerouslySetInnerHTML={{ __html: content }} />
      </div>
    </div>
  );
};

const MediaGallery = ({ items }) => {
  const [activeItem, setActiveItem] = useState(0);

  return (
    <div className="media-gallery grid grid-cols-3 gap-4 p-4">
      {items.map((item, index) => (
        <div key={item.id} 
             className="aspect-square overflow-hidden rounded-lg shadow-lg">
          <img 
            src={item.url} 
            alt={item.title}
            className="w-full h-full object-cover transition-all hover:scale-105"
            onClick={() => setActiveItem(index)}
            loading="lazy"
          />
        </div>
      ))}
    </div>
  );
};

const TemplateWrapper = ({ template, data, className = '' }) => {
  return (
    <div className={`template-wrapper ${className}`}>
      <div className="container mx-auto">
        <ContentDisplay content={template} />
        {data.gallery && <MediaGallery items={data.gallery} />}
      </div>
    </div>
  );
};

export default TemplateWrapper;
