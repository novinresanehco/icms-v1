import React, { useState } from 'react';

const GallerySystem = () => {
  const [selectedItem, setSelectedItem] = useState(null);
  const [displayMode, setDisplayMode] = useState('grid');

  return (
    <div className="w-full">
      <GalleryControls 
        onDisplayChange={setDisplayMode} 
        currentMode={displayMode}
      />
      <GalleryGrid 
        onSelect={setSelectedItem}
        displayMode={displayMode}
      />
      {selectedItem && (
        <GalleryModal 
          item={selectedItem} 
          onClose={() => setSelectedItem(null)}
        />
      )}
    </div>
  );
};

const GalleryControls = ({ onDisplayChange, currentMode }) => {
  return (
    <div className="flex items-center space-x-4 mb-4">
      <div className="flex bg-gray-100 rounded-lg p-1">
        <button
          onClick={() => onDisplayChange('grid')}
          className={`px-4 py-2 rounded ${
            currentMode === 'grid' 
              ? 'bg-white shadow-sm' 
              : 'text-gray-600'
          }`}
        >
          Grid
        </button>
        <button
          onClick={() => onDisplayChange('list')}
          className={`px-4 py-2 rounded ${
            currentMode === 'list' 
              ? 'bg-white shadow-sm' 
              : 'text-gray-600'
          }`}
        >
          List
        </button>
      </div>
    </div>
  );
};

const GalleryGrid = ({ onSelect, displayMode }) => {
  return (
    <div className={
      displayMode === 'grid' 
        ? 'grid grid-cols-1 md:grid-cols-3 gap-4'
        : 'space-y-4'
    }>
      <GalleryItem
        type="image"
        url="/api/placeholder/400/300"
        title="Example Image"
        onSelect={() => onSelect({ type: 'image', id: 1 })}
        displayMode={displayMode}
      />
    </div>
  );
};

const GalleryItem = ({ type, url, title, onSelect, displayMode }) => {
  return displayMode === 'grid' ? (
    <div 
      onClick={onSelect}
      className="cursor-pointer group relative overflow-hidden rounded-lg bg-gray-100"
    >
      <div className="aspect-w-16 aspect-h-9">
        <img 
          src={url} 
          alt={title}
          className="w-full h-full object-cover transition-transform group-hover:scale-105"
        />
      </div>
      <div className="absolute bottom-0 w-full bg-black bg-opacity-50 p-2 transform transition-transform translate-y-full group-hover:translate-y-0">
        <p className="text-white text-sm">{title}</p>
      </div>
    </div>
  ) : (
    <div 
      onClick={onSelect}
      className="flex items-center space-x-4 cursor-pointer hover:bg-gray-50 p-2 rounded-lg"
    >
      <div className="w-20 h-20 rounded-lg overflow-hidden bg-gray-100">
        <img 
          src={url} 
          alt={title}
          className="w-full h-full object-cover"
        />
      </div>
      <div>
        <p className="font-medium">{title}</p>
      </div>
    </div>
  );
};

const GalleryModal = ({ item, onClose }) => {
  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg max-w-4xl w-full">
        <div className="p-4 flex justify-between items-center border-b">
          <h3 className="font-medium">Media Preview</h3>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700"
          >
            Close
          </button>
        </div>
        <div className="p-4">
          <img 
            src={item.url || '/api/placeholder/800/600'} 
            alt={item.title}
            className="w-full rounded-lg"
          />
        </div>
      </div>
    </div>
  );
};

export default GallerySystem;
